#!/usr/bin/env bash
# Puts another game of RideIdle on a domain of its own, on the server that provision.sh set up. An installation runs several games: each has its own content, its
# own players and its own domain, and every domain is served from the same release (the web app from web/dist, the API from api/public). This script does the
# nginx part: it checks that the domain points here, gets a certificate of its own for it, and puts a site next to the one of the first game, made from the same
# template. The game itself (its name, its pack) is made in the panel and with artisan: it prints what is left. It does not touch the site of the first game or the
# one of the panel. Run it as root, on the server, from the deploy/ folder (the one of a release will do: /var/www/rideidle/current/deploy), once the first deploy
# has been made:
#
#   sudo env GAME_DOMAIN=ships.example.com GAME_EMAIL=you@example.com bash game-site.sh
#
# It can be run again: what is there is kept, and what changed in the template is put in place.
#
# Settings (environment variables)
#   GAME_DOMAIN         required. The host of the game. Its A record must point to this server before you run this. It must be the domain that the game has on the
#                       page "Gry" of the panel, because that is how a request finds its game.
#   GAME_EMAIL          required. Where Let's Encrypt writes about the certificate.
#   SKIP_DNS_CHECK=1    do not check that the host points here (do not, unless you know why)
#   CERTBOT_STAGING=1   ask Let's Encrypt for a test certificate (for trying this out without using up its limits)
#
# For the tests of this script only (a folder of stubs stands in for /etc/nginx and the rest):
#   NGINX_DIR           the folder of nginx (default /etc/nginx)
#   LETSENCRYPT_DIR     the folder of the certificates (default /etc/letsencrypt)
#   SKIP_ROOT_CHECK=1   do not insist on being root
#
# What it changes: /etc/nginx/sites-available/rideidle-game-<name>.conf (and the link to it; <name> is the domain with the dots made dashes) and a certificate of its
# own for the domain. Nothing else on the server: not the .env of the game, not PHP, not the other sites.

set -Eeuo pipefail
trap 'echo "game-site: failed at line ${LINENO} (exit code $?)" >&2' ERR
# Bash 5.2 turns a & in the replacement of ${var//a/b} into the text that was found: a value must go in as it is.
shopt -u patsub_replacement 2>/dev/null || true

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${RIDEIDLE_ENV:-/etc/rideidle/deploy.env}"
NGINX_DIR="${NGINX_DIR:-/etc/nginx}"
LETSENCRYPT_DIR="${LETSENCRYPT_DIR:-/etc/letsencrypt}"
SKIP_ROOT_CHECK="${SKIP_ROOT_CHECK:-0}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() { printf '\ngame-site: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 || "$SKIP_ROOT_CHECK" == "1" ]] || fail "Run this as root (sudo bash game-site.sh)."
[[ -r "$ENV_FILE" ]] || fail "$ENV_FILE is missing. Was provision.sh run on this server?"
# shellcheck disable=SC1090
. "$ENV_FILE"
: "${APP_DIR:?}" "${DOMAIN:?}" "${PHP_VERSION:?}"

# The domain is written in lower case: a host is compared in lower case, and this is the name of a file.
GAME_DOMAIN="$(printf '%s' "${GAME_DOMAIN:-}" | tr '[:upper:]' '[:lower:]')"
GAME_EMAIL="${GAME_EMAIL:-}"
SKIP_DNS_CHECK="${SKIP_DNS_CHECK:-0}"
CERTBOT_STAGING="${CERTBOT_STAGING:-0}"
ENV_OF_GAME="$APP_DIR/shared/.env"
SITES_AVAILABLE="$NGINX_DIR/sites-available"
SITES_ENABLED="$NGINX_DIR/sites-enabled"
# The slug-like name of the domain: ships.example.com -> ships-example-com.
SITE_NAME="${GAME_DOMAIN//./-}"
SITE="$SITES_AVAILABLE/rideidle-game-${SITE_NAME}.conf"
ENABLED="$SITES_ENABLED/rideidle-game-${SITE_NAME}.conf"
# What the site was before this run, if there was one: it is put back when nginx does not accept what replaces it.
BACKUP=""

# Puts the values in place of the __NAMES__ of the template. The domain is the one of the game; the folders and PHP are those of the installation.
render() {
    local content
    content="$(cat "$1")"
    content="${content//__DOMAIN__/$GAME_DOMAIN}"
    content="${content//__APP_DIR__/$APP_DIR}"
    content="${content//__PHP_VERSION__/$PHP_VERSION}"
    printf '%s\n' "$content"
}

# Keeps what the site is now (when there is one), for nginx_check_or_undo.
remember_site() {
    if [[ -f "$SITE" && -z "$BACKUP" ]]; then
        BACKUP="$(mktemp)"
        cat "$SITE" > "$BACKUP"
    fi
}

# Tests nginx, and if it does not accept the site takes it out again (or puts back what it was), so that nothing else on the server is left with a configuration
# that fails.
nginx_check_or_undo() {
    local log
    if log="$(nginx -t 2>&1)"; then
        return
    fi
    if [[ -n "$BACKUP" ]]; then
        cat "$BACKUP" > "$SITE"
    else
        rm -f "$ENABLED" "$SITE"
    fi
    printf '%s\n' "$log" >&2
    fail "nginx did not accept the site of ${GAME_DOMAIN}. It was taken out of ${NGINX_DIR} again (or put back as it was), so everything else is as it was."
}

check_settings() {
    say "Checking"

    [[ -n "$GAME_DOMAIN" ]] || fail "Set GAME_DOMAIN, for example GAME_DOMAIN=ships.example.com"
    [[ "$GAME_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || fail "GAME_DOMAIN does not look like a domain: $GAME_DOMAIN"
    [[ "$GAME_DOMAIN" == *.* ]] || fail "GAME_DOMAIN needs a dot in it (a full host name): $GAME_DOMAIN"
    [[ "$GAME_DOMAIN" != "$DOMAIN" ]] || fail "$DOMAIN is the first game. A game of its own needs a domain of its own."

    # The host of the panel is in the .env of the game, where admin-site.sh put it.
    local admin_domain=""
    if [[ -f "$ENV_OF_GAME" ]]; then
        admin_domain="$(sed -n 's/^ADMIN_DOMAIN=//p' "$ENV_OF_GAME" | tail -n 1 | tr -d '"' | tr '[:upper:]' '[:lower:]')"
    fi
    [[ -z "$admin_domain" || "$GAME_DOMAIN" != "$admin_domain" ]] || fail "$admin_domain is the host of the admin panel. A game needs a domain of its own."

    [[ -n "$GAME_EMAIL" ]] || fail "Set GAME_EMAIL, the address Let's Encrypt writes to about the certificate."
    [[ "$GAME_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || fail "GAME_EMAIL does not look like an e-mail address: $GAME_EMAIL"
    [[ -f "$HERE/nginx/rideidle.conf.template" ]] || fail "Run this from the deploy/ folder of the repository: nginx/rideidle.conf.template is missing next to it."
    [[ -f "$ENV_OF_GAME" ]] || fail "$ENV_OF_GAME is missing. Make the first deploy before this."
    [[ -f "$NGINX_DIR/snippets/rideidle-security.conf" && -f "$NGINX_DIR/conf.d/rideidle-global.conf" ]] || fail "The nginx of the game is not set up (provision.sh puts its settings in $NGINX_DIR). Run provision.sh first."
    command -v nginx >/dev/null 2>&1 || fail "nginx is not installed."
    command -v certbot >/dev/null 2>&1 || fail "certbot is not installed."
    nginx -t >/dev/null 2>&1 || fail "nginx does not accept its configuration as it is (nginx -t). Fix that first. Nothing was changed."

    # No other site may answer for this host (the site of this very game, from an earlier run, may).
    local clash
    clash="$(grep -rlE --exclude="$(basename "$SITE")" "server_name[^;]*[[:space:]]${GAME_DOMAIN//./\\.}[[:space:];]" "$NGINX_DIR" 2>/dev/null || true)"
    [[ -z "$clash" ]] || fail "A site of nginx already answers for ${GAME_DOMAIN} (${clash}). Nothing was changed."

    # Two domains can have the same name as a file (a-b.example.com and a.b.example.com): the file of another domain is not taken over.
    if [[ -f "$SITE" ]] && ! grep -qE "server_name[[:space:]]+${GAME_DOMAIN//./\\.};" "$SITE"; then
        fail "$SITE exists and is the site of another domain. Nothing was changed."
    fi

    [[ -d "$APP_DIR/current" ]] || note "There is no release live yet ($APP_DIR/current): the site will answer 404 until the first deploy."
}

check_dns() {
    say "Checking that $GAME_DOMAIN points to this server"

    if [[ "$SKIP_DNS_CHECK" == "1" ]]; then
        note "Skipped (SKIP_DNS_CHECK=1)."
        return
    fi

    local server_ip host_ips
    server_ip="$(curl -4fsS --max-time 10 https://api.ipify.org 2>/dev/null || curl -4fsS --max-time 10 https://ifconfig.me 2>/dev/null || true)"
    host_ips="$(getent ahostsv4 "$GAME_DOMAIN" | awk '{print $1}' | sort -u || true)"

    [[ -n "$host_ips" ]] || fail "$GAME_DOMAIN has no A record yet. Add an A record that points to this server (${server_ip:-its public address}) and wait for it to spread, then run this again."
    [[ -n "$server_ip" ]] || fail "Could not find out the public address of this server. If you are sure $GAME_DOMAIN points here, run with SKIP_DNS_CHECK=1."

    if ! grep -qx "$server_ip" <<<"$host_ips"; then
        fail "$GAME_DOMAIN points to $(tr '\n' ' ' <<<"$host_ips")but this server is $server_ip. Change the A record, wait for it to spread, and run this again."
    fi

    note "$GAME_DOMAIN -> $server_ip"
}

setup_site() {
    say "Getting a certificate for $GAME_DOMAIN"

    local cert="$LETSENCRYPT_DIR/live/${GAME_DOMAIN}/fullchain.pem"

    install -d "$SITES_AVAILABLE" "$SITES_ENABLED"
    remember_site

    if [[ ! -f "$cert" ]]; then
        # Until there is a certificate, the site only answers the check of Let's Encrypt.
        cat > "$SITE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${GAME_DOMAIN};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 404; }
}
EOF
        ln -sf "$SITE" "$ENABLED"
        nginx_check_or_undo
        systemctl reload nginx

        local staging=()
        [[ "$CERTBOT_STAGING" == "1" ]] && staging=(--test-cert)

        # The certificate has a name of its own, so it is renewed and replaced apart from the one of the first game and the one of the panel.
        certbot certonly --webroot -w /var/www/certbot -d "$GAME_DOMAIN" --cert-name "$GAME_DOMAIN" -m "$GAME_EMAIL" \
            --agree-tos --no-eff-email --non-interactive --deploy-hook "systemctl reload nginx" "${staging[@]}"
    else
        note "There is a certificate already."
    fi

    say "Putting the site of the game in place"

    render "$HERE/nginx/rideidle.conf.template" > "$SITE"
    ln -sf "$SITE" "$ENABLED"
    nginx_check_or_undo
    systemctl reload nginx
}

check_settings
check_dns
setup_site

[[ -z "$BACKUP" ]] || rm -f "$BACKUP"

SLUG_HINT="${GAME_DOMAIN%%.*}"

cat <<EOF

The site of ${GAME_DOMAIN} is in place: https://${GAME_DOMAIN}

What is left to do by hand (deploy/README.md describes it):

  1. In the panel, on the page "Gry", the game with this very domain: ${GAME_DOMAIN}
     (its slug is the name that the commands below take; status "live" when the game is ready to be played). A request for a host that no game has is
     answered 404, so until the game is there the site answers 404 for the API.

  2. The pack of the game. With the content of the repository (a game of the same cars to start from), where SLUG is the slug that the game has on the page
     "Gry" (for example ${SLUG_HINT}):

       cd ${APP_DIR}/current/api && sudo -u www-data php${PHP_VERSION} artisan content:import --game=SLUG --activate

     or a bundle file from another game or environment, on the page "Paczki" of the panel, with the game chosen at the top of the page.

  3. Look at it:

       curl -s https://${GAME_DOMAIN}/api/v1/meta/game
       curl -s https://${GAME_DOMAIN}/up

If you sign in with Google, Facebook or Apple, register https://${GAME_DOMAIN}/api/v1/auth/<provider>/callback with the provider for this domain.
EOF
