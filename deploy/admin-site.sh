#!/usr/bin/env bash
# Puts the admin panel of RideIdle on a host of its own (admin.<your domain>) on the server that provision.sh set up. It does not touch the site of the game: it adds
# a certificate and a site to nginx, and tells the game the name of the host. Run it as root, on the server, from the deploy/ folder (the one of a release will do:
# /var/www/rideidle/current/deploy), once the first deploy has been made:
#
#   sudo env ADMIN_DOMAIN=admin.raceidle.cerasusdigital.pl ADMIN_EMAIL=you@example.com bash admin-site.sh
#
# It can be run again: what is there is kept, and what changed in the template is put in place.
#
# Settings (environment variables)
#   ADMIN_DOMAIN        required. The host of the panel. Its A record must point to this server before you run this.
#   ADMIN_EMAIL         required. Where Let's Encrypt writes about the certificate.
#   SKIP_DNS_CHECK=1    do not check that the host points here (do not, unless you know why)
#   CERTBOT_STAGING=1   ask Let's Encrypt for a test certificate (for trying this out without using up its limits)
#
# What it changes: /etc/nginx/sites-available/rideidle-admin.conf (and the link to it), a certificate of its own for the host, and ADMIN_DOMAIN in the .env of the game
# (the caches of Laravel are made again, because the routes of the panel are tied to the host). The people who may use the panel are made on the server with
# `artisan admin:create`: see deploy/README.md.

set -Eeuo pipefail
trap 'echo "admin-site: failed at line ${LINENO} (exit code $?)" >&2' ERR
# Bash 5.2 turns a & in the replacement of ${var//a/b} into the text that was found: a value must go in as it is.
shopt -u patsub_replacement 2>/dev/null || true

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${RIDEIDLE_ENV:-/etc/rideidle/deploy.env}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() { printf '\nadmin-site: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run this as root (sudo bash admin-site.sh)."
[[ -r "$ENV_FILE" ]] || fail "$ENV_FILE is missing. Was provision.sh run on this server?"
# shellcheck disable=SC1090
. "$ENV_FILE"
: "${APP_DIR:?}" "${DOMAIN:?}" "${PHP_VERSION:?}"

ADMIN_DOMAIN="${ADMIN_DOMAIN:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
SKIP_DNS_CHECK="${SKIP_DNS_CHECK:-0}"
CERTBOT_STAGING="${CERTBOT_STAGING:-0}"
ENV_OF_GAME="$APP_DIR/shared/.env"
SITE="/etc/nginx/sites-available/rideidle-admin.conf"
ENABLED="/etc/nginx/sites-enabled/rideidle-admin.conf"

# Puts the values in place of the __NAMES__ of the template.
render() {
    local content
    content="$(cat "$1")"
    content="${content//__ADMIN_DOMAIN__/$ADMIN_DOMAIN}"
    content="${content//__APP_DIR__/$APP_DIR}"
    content="${content//__PHP_VERSION__/$PHP_VERSION}"
    printf '%s\n' "$content"
}

# Tests nginx, and if it does not accept the site takes it out again, so that nothing else on the server is left with a configuration that fails.
nginx_check_or_undo() {
    local log
    if log="$(nginx -t 2>&1)"; then
        return
    fi
    rm -f "$ENABLED"
    printf '%s\n' "$log" >&2
    fail "nginx did not accept the site of the panel. It was taken out of /etc/nginx again, so everything else is as it was."
}

check_settings() {
    say "Checking"

    [[ -n "$ADMIN_DOMAIN" ]] || fail "Set ADMIN_DOMAIN, for example ADMIN_DOMAIN=admin.${DOMAIN}"
    [[ "$ADMIN_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || fail "ADMIN_DOMAIN does not look like a domain: $ADMIN_DOMAIN"
    [[ "$ADMIN_DOMAIN" != "$DOMAIN" ]] || fail "The panel needs a host of its own. $DOMAIN is the game."
    [[ -n "$ADMIN_EMAIL" ]] || fail "Set ADMIN_EMAIL, the address Let's Encrypt writes to about the certificate."
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || fail "ADMIN_EMAIL does not look like an e-mail address: $ADMIN_EMAIL"
    [[ -f "$HERE/nginx/rideidle-admin.conf.template" ]] || fail "Run this from the deploy/ folder of the repository: nginx/rideidle-admin.conf.template is missing next to it."
    [[ -f "$ENV_OF_GAME" ]] || fail "$ENV_OF_GAME is missing. Make the first deploy before this."
    [[ -f /etc/nginx/snippets/rideidle-security.conf && -f /etc/nginx/conf.d/rideidle-global.conf ]] || fail "The nginx of the game is not set up (provision.sh puts its settings in /etc/nginx). Run provision.sh first."
    command -v nginx >/dev/null 2>&1 || fail "nginx is not installed."
    command -v certbot >/dev/null 2>&1 || fail "certbot is not installed."
    nginx -t >/dev/null 2>&1 || fail "nginx does not accept its configuration as it is (nginx -t). Fix that first. Nothing was changed."

    # No other site may answer for this host.
    local clash
    clash="$(grep -rlE --exclude=rideidle-admin.conf "server_name[^;]*[[:space:]]${ADMIN_DOMAIN//./\\.}[[:space:];]" /etc/nginx 2>/dev/null || true)"
    [[ -z "$clash" ]] || fail "A site of nginx already answers for ${ADMIN_DOMAIN} (${clash}). Nothing was changed."
}

check_dns() {
    say "Checking that $ADMIN_DOMAIN points to this server"

    if [[ "$SKIP_DNS_CHECK" == "1" ]]; then
        note "Skipped (SKIP_DNS_CHECK=1)."
        return
    fi

    local server_ip host_ips
    server_ip="$(curl -4fsS --max-time 10 https://api.ipify.org 2>/dev/null || curl -4fsS --max-time 10 https://ifconfig.me 2>/dev/null || true)"
    host_ips="$(getent ahostsv4 "$ADMIN_DOMAIN" | awk '{print $1}' | sort -u || true)"

    [[ -n "$host_ips" ]] || fail "$ADMIN_DOMAIN has no A record yet. Add an A record that points to this server (${server_ip:-its public address}) and wait for it to spread, then run this again."
    [[ -n "$server_ip" ]] || fail "Could not find out the public address of this server. If you are sure $ADMIN_DOMAIN points here, run with SKIP_DNS_CHECK=1."

    if ! grep -qx "$server_ip" <<<"$host_ips"; then
        fail "$ADMIN_DOMAIN points to $(tr '\n' ' ' <<<"$host_ips")but this server is $server_ip. Change the A record, wait for it to spread, and run this again."
    fi

    note "$ADMIN_DOMAIN -> $server_ip"
}

setup_site() {
    say "Getting a certificate for $ADMIN_DOMAIN"

    local cert="/etc/letsencrypt/live/${ADMIN_DOMAIN}/fullchain.pem"

    if [[ ! -f "$cert" ]]; then
        # Until there is a certificate, the site only answers the check of Let's Encrypt.
        cat > "$SITE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${ADMIN_DOMAIN};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 404; }
}
EOF
        ln -sf "$SITE" "$ENABLED"
        nginx_check_or_undo
        systemctl reload nginx

        local staging=()
        [[ "$CERTBOT_STAGING" == "1" ]] && staging=(--test-cert)

        # The certificate has a name of its own, so it is renewed and replaced apart from the one of the game.
        certbot certonly --webroot -w /var/www/certbot -d "$ADMIN_DOMAIN" --cert-name "$ADMIN_DOMAIN" -m "$ADMIN_EMAIL" \
            --agree-tos --no-eff-email --non-interactive --deploy-hook "systemctl reload nginx" "${staging[@]}"
    else
        note "There is a certificate already."
    fi

    say "Putting the site of the panel in place"

    render "$HERE/nginx/rideidle-admin.conf.template" > "$SITE"
    ln -sf "$SITE" "$ENABLED"
    nginx_check_or_undo
    systemctl reload nginx
}

# A value that goes between double quotes in a .env: a \ escapes there, a $ starts a variable and a " ends the value.
env_value() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    printf '%s' "$value"
}

# set_env KEY VALUE [keep]: the setting is replaced where it is, or added at the end (with `keep`, one that is there stays as it is); everything else in the
# .env stays as it is, with its owner and its rights.
set_env() {
    local key="$1" value="$2" keep="${3:-}" line tmp
    line="${key}=\"$(env_value "$value")\""

    if grep -q "^${key}=" "$ENV_OF_GAME"; then
        [[ "$keep" == "keep" ]] && return
        tmp="$(mktemp)"
        # The line goes in through the environment: awk -v would take the backslashes of a value for escapes of its own.
        KEY="$key" LINE="$line" awk 'index($0, ENVIRON["KEY"] "=") == 1 { print ENVIRON["LINE"]; next } { print }' "$ENV_OF_GAME" > "$tmp"
    else
        tmp="$(mktemp)"
        { cat "$ENV_OF_GAME"; printf '%s\n' "$line"; } > "$tmp"
    fi

    cat "$tmp" > "$ENV_OF_GAME"
    rm -f "$tmp"
}

tell_the_game() {
    say "Telling the game where the panel is"

    set_env ADMIN_DOMAIN "$ADMIN_DOMAIN"
    # The panel signs people in with a cookie, which must not travel over anything but HTTPS (the game itself uses tokens, not cookies).
    set_env SESSION_SECURE_COOKIE true keep

    # The routes of the panel are tied to the host, and the cached ones are made from the .env of the moment they were made.
    if [[ -f "$APP_DIR/current/api/artisan" ]]; then
        (cd "$APP_DIR/current/api" && sudo -n -u www-data -H "/usr/bin/php${PHP_VERSION}" artisan config:cache && sudo -n -u www-data -H "/usr/bin/php${PHP_VERSION}" artisan route:cache)
        # opcache does not look at the files again (provision.sh turns validate_timestamps off), so the workers that are running keep the old config and routes, and the
        # panel would answer under /admin of its host, not at / of it, until PHP is reloaded.
        systemctl reload "php${PHP_VERSION}-fpm"
    else
        note "There is no release live yet: the next deploy makes the caches."
    fi
}

check_settings
check_dns
setup_site
tell_the_game

cat <<EOF

The panel is at https://${ADMIN_DOMAIN}

Make the first publisher (the password is shown once; the authenticator app is set up at the first sign-in):

  cd ${APP_DIR}/current/api && sudo -u www-data php${PHP_VERSION} artisan admin:create you@example.com --role=publisher

More about the panel, the roles and what to do when somebody loses the phone: deploy/README.md.
EOF
