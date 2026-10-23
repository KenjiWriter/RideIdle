#!/usr/bin/env bash
# Puts a release of RideIdle live on the server. provision.sh installs this as /var/www/idlerace/bin/deploy.sh, and it is run
# as the user that deploys (deploy), usually by deploy/upload.sh from your computer:
#
#   deploy.sh --source DIR [--label NAME]    a release from the files in DIR (upload.sh puts them in /var/www/idlerace/incoming)
#   deploy.sh --repo URL [--ref BRANCH]      a release from a git repository (BRANCH defaults to main)
#   deploy.sh --rollback                     back to the release that was live before the current one
#
# A release is built in a folder of its own and goes live all at once, by changing the link `current`, so a visitor never sees a
# half built game and a failed build changes nothing:
#
#   1. the source is put in releases/<time>   2. composer install (API)   3. npm ci and npm run build (web)
#   4. the .env and the storage are linked in from shared/   5. content:validate   6. the migrations
#   7. the caches of Laravel   8. `current` points to the release   9. PHP-FPM is reloaded and the queue worker restarted
#  10. the site is asked if it is well; if it is not, the previous release is put back and this exits with an error
#
# The migrations run BEFORE the link changes, while the old release is still serving. So a migration must work for the old
# code as well (add a column, do not rename or remove one in the same release), and a rollback puts the old code back on
# the new database but does not undo the migrations.

set -Eeuo pipefail

ENV_FILE="${RIDEIDLE_ENV:-/etc/rideidle/deploy.env}"
[[ -r "$ENV_FILE" ]] || { echo "deploy: $ENV_FILE is missing. Was provision.sh run on this server?" >&2; exit 1; }
# shellcheck disable=SC1090
. "$ENV_FILE"

: "${APP_DIR:?}" "${DOMAIN:?}" "${PHP_VERSION:?}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
RELEASES="$APP_DIR/releases"
CURRENT="$APP_DIR/current"
PHP="/usr/bin/php${PHP_VERSION}"
# The Node of the build: the one provision.sh put in its own folder when the server is shared with other sites (SHARED_SERVER),
# otherwise the one of the system.
if [[ -x /opt/rideidle/node/bin/node ]]; then
    PATH="/opt/rideidle/node/bin:$PATH"
fi
# Options for the check of the site: for a test certificate (CERTBOT_STAGING) that no browser trusts, add -k here.
HEALTH_CURL_OPTS="${HEALTH_CURL_OPTS:-}"

umask 027

SOURCE="" REPO="" REF="main" LABEL="" ROLLBACK=0
RELEASE="" PREVIOUS="" SWITCHED=0

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\ndeploy: %s\n' "$*" >&2; exit 1; }

usage() {
    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while (($#)); do
    case "$1" in
        --source) SOURCE="${2:?--source needs a folder}"; shift 2 ;;
        --repo) REPO="${2:?--repo needs an address}"; shift 2 ;;
        --ref) REF="${2:?--ref needs a branch or tag}"; shift 2 ;;
        --label) LABEL="${2:?--label needs a name}"; shift 2 ;;
        --rollback) ROLLBACK=1; shift ;;
        -h | --help) usage 0 ;;
        *) echo "deploy: I do not know $1" >&2; usage 1 ;;
    esac
done

# Only one deploy at a time.
exec 9>"$APP_DIR/.deploy.lock"
flock -n 9 || die "Another deploy is running."

# The commands of the application run as the user of the web server, so that what they write can be read and written by it.
artisan() {
    (cd "$RELEASE/api" && sudo -n -u www-data -H "$PHP" artisan "$@")
}

live_release() { readlink -f "$CURRENT" 2>/dev/null || true; }

switch_to() {
    ln -sfn "$1" "$APP_DIR/current.new"
    mv -Tf "$APP_DIR/current.new" "$CURRENT"
}

restart_services() {
    sudo -n /usr/bin/systemctl reload "php${PHP_VERSION}-fpm"
    sudo -n /usr/bin/systemctl restart rideidle-queue
}

# Is the site well? The API answers, the app is served, and Laravel says it is up. Asked through this server itself, with the
# name of the domain, so that it does not matter whether DNS or a proxy is in the way.
healthy() {
    local base="https://${DOMAIN}" resolve="${DOMAIN}:443:127.0.0.1"
    # shellcheck disable=SC2086
    for _ in 1 2 3 4 5 6; do
        if curl -fsS --max-time 15 $HEALTH_CURL_OPTS --resolve "$resolve" "$base/up" -o /dev/null 2>/dev/null \
            && curl -fsS --max-time 15 $HEALTH_CURL_OPTS --resolve "$resolve" "$base/api/v1/meta/locales" 2>/dev/null | grep -q '"data"' \
            && curl -fsS --max-time 15 $HEALTH_CURL_OPTS --resolve "$resolve" "$base/" 2>/dev/null | grep -q 'id="app"'; then
            return 0
        fi
        sleep 3
    done
    return 1
}

# The newest release that is OLDER than the live one. (Not just "another one": after a rollback the release that was left is
# newer than the live one, and going back again must not return to it.)
previous_release() {
    local live dir seen=0
    live="$(live_release)"
    while IFS= read -r dir; do
        if ((seen)); then
            echo "$dir"
            return
        fi
        if [[ "$(readlink -f "$dir")" == "$live" ]]; then
            seen=1
        fi
    done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d | sort -r)
}

prune_old_releases() {
    local live
    live="$(live_release)"
    find "$RELEASES" -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +"$((KEEP_RELEASES + 1))" | while read -r dir; do
        [[ "$(readlink -f "$dir")" == "$live" ]] || rm -rf "$dir"
    done
}

# ----------------------------------------------------------------------------------------------------------------------

if ((ROLLBACK)); then
    say "Rolling back"
    PREVIOUS="$(previous_release)"
    [[ -n "$PREVIOUS" ]] || die "There is no earlier release to go back to."

    echo "    from $(basename "$(live_release)") to $(basename "$PREVIOUS")"
    switch_to "$PREVIOUS"
    restart_services
    healthy || die "Went back to $(basename "$PREVIOUS"), but the site is not well. Look at /var/log/nginx/idlerace.error.log and ${APP_DIR}/shared/storage/logs."
    echo "    the site is well. The migrations were not undone."
    exit 0
fi

[[ -n "$SOURCE" || -n "$REPO" ]] || { echo "deploy: say where the source is: --source DIR or --repo URL" >&2; usage 1; }
[[ -z "$SOURCE" || -d "$SOURCE" ]] || die "$SOURCE is not a folder."

for tool in composer npm node rsync git curl flock; do
    command -v "$tool" >/dev/null || die "$tool is not installed. Was provision.sh run on this server?"
done
[[ -f "$APP_DIR/shared/.env" ]] || die "$APP_DIR/shared/.env is missing."

NAME="$(date +%Y%m%d-%H%M%S)${LABEL:+-$LABEL}"
RELEASE="$RELEASES/$NAME"
PREVIOUS="$(live_release)"

# A failure before the link changed leaves nothing behind (after it, the release is kept to look at, and the part below that
# checks the site puts the previous one back).
on_error() {
    printf '\ndeploy: failed at line %s (exit code %s)\n' "$1" "$2" >&2
}
trap 'on_error $LINENO $?' ERR

cleanup() {
    local code=$?
    if ((code != 0 && !SWITCHED)) && [[ -n "$RELEASE" && -d "$RELEASE" ]]; then
        echo "deploy: the release was not made live; removing $NAME" >&2
        rm -rf "$RELEASE"
    fi
}
trap cleanup EXIT

say "Release $NAME"
install -d -m 750 -g www-data "$RELEASE"

say "Fetching the source"
if [[ -n "$SOURCE" ]]; then
    rsync -a --delete --exclude '/.git' "$SOURCE"/ "$RELEASE"/
else
    git clone --quiet --depth 1 --branch "$REF" "$REPO" "$RELEASE"
    rm -rf "$RELEASE/.git"
fi
[[ -f "$RELEASE/api/artisan" && -f "$RELEASE/web/package.json" && -d "$RELEASE/content" ]] \
    || die "This is not the RideIdle repository: api/, web/ and content/ are not all there."

say "Installing the packages of the API (composer)"
# Composer is run by this PHP by name: on a server whose default `php` is another version it would check the packages against that.
(cd "$RELEASE/api" && "$PHP" "$(command -v composer)" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader)

say "Linking the .env and the storage"
ln -sfn "$APP_DIR/shared/.env" "$RELEASE/api/.env"
rm -rf "$RELEASE/api/storage"
ln -sfn "$APP_DIR/shared/storage" "$RELEASE/api/storage"
mkdir -p "$RELEASE/api/bootstrap/cache"

say "Building the web app (npm)"
(
    cd "$RELEASE/web"
    export NODE_OPTIONS="--max-old-space-size=1024"
    npm ci --no-audit --no-fund
    npm run build
)
[[ -f "$RELEASE/web/dist/index.html" ]] || die "The build of the web app made no index.html."
rm -rf "$RELEASE/web/node_modules"

say "Setting who may read what"
chgrp -R www-data "$RELEASE"
chmod -R g+rX,o-rwx "$RELEASE"
# The caches of Laravel are written by the user of the web server.
chmod -R g+ws "$RELEASE/api/bootstrap/cache"

say "Checking the content of the game"
artisan content:validate

say "Running the migrations"
artisan migrate --force --no-interaction

say "Making the caches of Laravel"
artisan config:cache
artisan route:cache
artisan event:cache
artisan view:cache

say "Going live"
switch_to "$RELEASE"
SWITCHED=1
restart_services

say "Is the site well?"
if healthy; then
    echo "    yes: https://${DOMAIN}"
else
    echo "    NO. Putting the previous release back." >&2
    if [[ -n "$PREVIOUS" && -d "$PREVIOUS" ]]; then
        switch_to "$PREVIOUS"
        restart_services
        healthy && echo "    the previous release ($(basename "$PREVIOUS")) is back and the site is well." >&2 || echo "    the previous release is back, but the site is still not well." >&2
    else
        echo "    there is no previous release." >&2
    fi
    echo "    Look at /var/log/nginx/idlerace.error.log and ${APP_DIR}/shared/storage/logs. The failed release is kept as $NAME." >&2
    exit 1
fi

prune_old_releases

say "Done: $NAME is live"
