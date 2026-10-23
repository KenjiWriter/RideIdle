#!/usr/bin/env bash
# Sets up a fresh Ubuntu server (22.04 or 24.04) to run RideIdle on one domain: the web app, the API, PostgreSQL, Redis,
# nginx with a certificate from Let's Encrypt, the queue worker, the scheduler, backups and a firewall.
#
# Run it ONCE, as root, on the server, from the deploy/ folder of the repository (deploy/upload.sh bootstrap puts it there):
#
#   DOMAIN=idlerace.cerasusdigital.pl ADMIN_EMAIL=you@example.com bash provision.sh
#
# It can be run again: what is there is kept (the .env is never overwritten, the database and its user are not made twice),
# and what changed in the templates is put in place. It does not deploy the application: that is deploy/deploy.sh.
#
# Settings (environment variables)
#   DOMAIN              required. The domain of the game. Its A record must point to this server before you run this.
#   ADMIN_EMAIL         required. Where Let's Encrypt writes about the certificate.
#   MAIL_FROM_ADDRESS   the sender of the e-mails. Default: no-reply@cerasusdigital.pl
#   MAIL_HOST MAIL_PORT MAIL_SCHEME MAIL_USERNAME MAIL_PASSWORD   the SMTP server that sends the e-mails (can be set later in the .env)
#   LEGAL_OPERATOR_NAME LEGAL_OPERATOR_ADDRESS LEGAL_CONTACT_EMAIL   who runs the game (can be set later in the .env)
#   SSH_PORT            the port of SSH, which the firewall keeps open. Default: 22
#   DEPLOY_USER         the user that deploys. Default: deploy
#   APP_DIR             where the game lives. Default: /var/www/idlerace
#   PHP_VERSION         Default: 8.4        NODE_MAJOR  Default: 24
#   SKIP_DNS_CHECK=1    do not check that the domain points here (do not, unless you know why)
#   CERTBOT_STAGING=1   ask Let's Encrypt for a test certificate (for trying this out without using up its limits)

set -Eeuo pipefail
trap 'echo "provision: failed at line ${LINENO} (exit code $?)" >&2' ERR
# Bash 5.2 (Ubuntu 24.04) turns a & in the replacement of ${var//a/b} into the text that was found. A password or an address
# with a & in it must be put in a file as it is. (Older bash has no such option: the error is ignored.)
shopt -u patsub_replacement 2>/dev/null || true

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

DOMAIN="${DOMAIN:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
APP_DIR="${APP_DIR:-/var/www/idlerace}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_MAJOR="${NODE_MAJOR:-24}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
SSH_PORT="${SSH_PORT:-22}"
DB_NAME="rideidle"
DB_USER="rideidle"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-no-reply@cerasusdigital.pl}"
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-587}"
MAIL_SCHEME="${MAIL_SCHEME:-smtp}"
MAIL_USERNAME="${MAIL_USERNAME:-}"
MAIL_PASSWORD="${MAIL_PASSWORD:-}"
LEGAL_OPERATOR_NAME="${LEGAL_OPERATOR_NAME:-}"
LEGAL_OPERATOR_ADDRESS="${LEGAL_OPERATOR_ADDRESS:-}"
LEGAL_CONTACT_EMAIL="${LEGAL_CONTACT_EMAIL:-}"
SKIP_DNS_CHECK="${SKIP_DNS_CHECK:-0}"
CERTBOT_STAGING="${CERTBOT_STAGING:-0}"

export DEBIAN_FRONTEND=noninteractive

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() { printf '\nprovision: %s\n' "$*" >&2; exit 1; }

# Puts the values in place of the __NAMES__ of a template. Plain bash, so that a value with a / or & in it is safe.
render() {
    local content
    content="$(cat "$1")"
    content="${content//__DOMAIN__/$DOMAIN}"
    content="${content//__APP_DIR__/$APP_DIR}"
    content="${content//__PHP_VERSION__/$PHP_VERSION}"
    printf '%s\n' "$content"
}

# A value that goes between double quotes in a .env. Inside them a \ escapes, a $ starts a variable and a " ends the value, so
# these three get a \ before them; a line break cannot be in a value and is dropped.
env_value() {
    local value="$1"
    value="${value//$'\r'/}"
    value="${value//$'\n'/}"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    printf '%s' "$value"
}

# Is the setting in the .env given a value? (The values are in double quotes, so `KEY=""` is no value.)
env_is_set() {
    grep -Eq "^${1}=\"?[^\"[:space:]]" "$2"
}

# A folder whose new files belong to its group, which is what the web server and the user that deploys share.
make_folder() {
    local mode="$1" owner="$2" group="$3"
    shift 3
    install -d -m "$mode" -o "$owner" -g "$group" "$@"
    chmod g+s "$@"
}

# ----------------------------------------------------------------------------------------------------------------------

check_system() {
    say "Checking the system"

    [[ $EUID -eq 0 ]] || fail "Run this as root (sudo bash provision.sh)."
    [[ -n "$DOMAIN" ]] || fail "Set DOMAIN, for example DOMAIN=idlerace.cerasusdigital.pl"
    [[ -n "$ADMIN_EMAIL" ]] || fail "Set ADMIN_EMAIL, the address Let's Encrypt writes to about the certificate."
    [[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || fail "DOMAIN does not look like a domain: $DOMAIN"

    [[ -r /etc/os-release ]] || fail "This is not a system I know."
    # shellcheck disable=SC1091
    . /etc/os-release
    [[ "${ID:-}" == "ubuntu" ]] || fail "This script is for Ubuntu. This is ${PRETTY_NAME:-unknown}."
    case "${VERSION_ID:-}" in
        22.04 | 24.04) note "Ubuntu ${VERSION_ID}" ;;
        *) fail "Ubuntu 22.04 or 24.04 is what this was written for. This is ${VERSION_ID:-unknown}." ;;
    esac

    for file in nginx/idlerace.conf.template nginx/rideidle-global.conf nginx/rideidle-security.conf \
        systemd/rideidle-queue.service.template env.production.example bin/rideidle-backup deploy.sh; do
        [[ -f "$HERE/$file" ]] || fail "Run this from the deploy/ folder of the repository: $file is missing next to it."
    done
}

check_dns() {
    say "Checking that $DOMAIN points to this server"

    if [[ "$SKIP_DNS_CHECK" == "1" ]]; then
        note "Skipped (SKIP_DNS_CHECK=1)."
        return
    fi

    apt-get update -qq
    apt-get install -y -qq curl ca-certificates >/dev/null

    local server_ip domain_ips
    server_ip="$(curl -4fsS --max-time 10 https://api.ipify.org 2>/dev/null || curl -4fsS --max-time 10 https://ifconfig.me 2>/dev/null || true)"
    domain_ips="$(getent ahostsv4 "$DOMAIN" | awk '{print $1}' | sort -u || true)"

    [[ -n "$domain_ips" ]] || fail "$DOMAIN has no A record yet. Add an A record that points to this server (${server_ip:-its public address}) and wait for it to spread, then run this again."
    [[ -n "$server_ip" ]] || fail "Could not find out the public address of this server. If you are sure $DOMAIN points here, run with SKIP_DNS_CHECK=1."

    if ! grep -qx "$server_ip" <<<"$domain_ips"; then
        fail "$DOMAIN points to $(tr '\n' ' ' <<<"$domain_ips")but this server is $server_ip. Change the A record, wait for it to spread, and run this again."
    fi

    note "$DOMAIN -> $server_ip"
}

ensure_swap() {
    # Building the web app and installing the packages of the API need memory. A small server gets some swap.
    local memory_kb
    memory_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"

    if (( memory_kb < 2000000 )) && [[ -z "$(swapon --show --noheadings)" ]]; then
        say "Adding 2 GB of swap (this server has less than 2 GB of memory)"
        fallocate -l 2G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile >/dev/null
        swapon /swapfile
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    fi
}

install_base() {
    say "Installing the basic packages"
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg lsb-release software-properties-common git unzip rsync \
        ufw fail2ban unattended-upgrades cron logrotate >/dev/null

    # Security updates of the system are installed by themselves.
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
}

install_php() {
    say "Installing PHP ${PHP_VERSION}"

    if ! grep -rqs 'ondrej/php' /etc/apt/sources.list.d/; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
    fi
    apt-get update -qq

    apt-get install -y -qq "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-redis" >/dev/null
    # Where PHP has opcache as a package of its own, it is there; where it is built in, there is nothing to install.
    apt-get install -y -qq "php${PHP_VERSION}-opcache" >/dev/null 2>&1 || true

    update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null

    # Opcache keeps the compiled code and never looks at the files again: the deploy script reloads PHP-FPM after a release.
    cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-rideidle.ini" <<'EOF'
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
realpath_cache_ttl = 600
memory_limit = 256M
EOF

    systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null
    systemctl reload "php${PHP_VERSION}-fpm"
}

install_composer() {
    say "Installing Composer"

    if command -v composer >/dev/null; then
        note "Composer is there: $(composer --version --no-ansi 2>/dev/null | head -n1)"
        return
    fi

    local expected actual
    expected="$(curl -fsS https://composer.github.io/installer.sig)"
    curl -fsS https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || { rm -f /tmp/composer-setup.php; fail "The installer of Composer is not the one that was signed. Nothing was installed."; }

    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
}

install_node() {
    say "Installing Node ${NODE_MAJOR} (it builds the web app)"

    if command -v node >/dev/null && [[ "$(node -p 'process.versions.node.split(".")[0]')" == "$NODE_MAJOR" ]]; then
        note "Node is there: $(node --version)"
        return
    fi

    install -d -m 755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq
    apt-get install -y -qq nodejs >/dev/null
}

install_postgres() {
    say "Installing PostgreSQL and creating the database"
    apt-get install -y -qq postgresql >/dev/null
    systemctl enable --now postgresql >/dev/null

    local exists
    exists="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'")"
    if [[ "$exists" != "1" ]]; then
        runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qc "CREATE ROLE ${DB_USER} LOGIN" >/dev/null
    fi

    exists="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'")"
    if [[ "$exists" != "1" ]]; then
        runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qc "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING 'UTF8'" >/dev/null
    fi
}

install_redis() {
    say "Installing Redis (it only listens on this server)"
    apt-get install -y -qq redis-server >/dev/null

    # Ubuntu binds Redis to the loopback by default. Make sure, so that a changed default cannot open it to the world.
    if ! grep -Eq '^bind 127\.0\.0\.1' /etc/redis/redis.conf; then
        sed -i -E 's/^bind .*/bind 127.0.0.1 -::1/' /etc/redis/redis.conf
    fi
    sed -i -E 's/^protected-mode .*/protected-mode yes/' /etc/redis/redis.conf
    systemctl enable redis-server >/dev/null
    systemctl restart redis-server
}

create_users_and_folders() {
    say "Creating the user that deploys and the folders of the game"

    if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
        adduser --disabled-password --gecos "" "$DEPLOY_USER" >/dev/null
    fi
    usermod -aG www-data "$DEPLOY_USER"

    # The same key that lets you in as root lets you in as the user that deploys.
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"
    if [[ -f /root/.ssh/authorized_keys ]] && [[ ! -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]]; then
        install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
        note "The SSH key of root was copied to ${DEPLOY_USER}."
    fi

    # releases/    one folder per release         shared/   what every release uses: the .env and the storage
    # current      a link to the release that is live      incoming/   where upload.sh puts the source
    make_folder 750 "$DEPLOY_USER" www-data "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/incoming" "$APP_DIR/bin"
    make_folder 770 "$DEPLOY_USER" www-data "$APP_DIR/shared"
    for dir in app/public framework/cache/data framework/sessions framework/views logs; do
        make_folder 770 www-data www-data "$APP_DIR/shared/storage/$dir"
    done
    # The folders that hold them, up to storage, belong to the web server as well (install -d only sets the last one).
    chown www-data:www-data "$APP_DIR/shared/storage" "$APP_DIR/shared/storage/app" "$APP_DIR/shared/storage/framework" "$APP_DIR/shared/storage/framework/cache"
    chmod 2770 "$APP_DIR/shared/storage" "$APP_DIR/shared/storage/app" "$APP_DIR/shared/storage/framework" "$APP_DIR/shared/storage/framework/cache"
    install -d -m 755 /var/www/certbot

    # What the deploy script needs to know.
    install -d -m 755 /etc/rideidle
    cat > /etc/rideidle/deploy.env <<EOF
APP_DIR=${APP_DIR}
DOMAIN=${DOMAIN}
PHP_VERSION=${PHP_VERSION}
DEPLOY_USER=${DEPLOY_USER}
KEEP_RELEASES=5
EOF
    chmod 644 /etc/rideidle/deploy.env

    install -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$HERE/deploy.sh" "$APP_DIR/bin/deploy.sh"
}

write_env() {
    say "Writing the .env of the API"

    local env_file="$APP_DIR/shared/.env"
    if [[ -f "$env_file" ]]; then
        note "$env_file is there and stays as it is."
        return
    fi

    local app_key db_password content
    app_key="base64:$(openssl rand -base64 32)"
    db_password="$(openssl rand -hex 24)"

    # The user of the database gets the password that goes into the .env, and only that one.
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qc "ALTER ROLE ${DB_USER} PASSWORD '${db_password}'" >/dev/null

    content="$(cat "$HERE/env.production.example")"
    content="${content//__DOMAIN__/$DOMAIN}"
    content="${content//__APP_KEY__/$app_key}"
    content="${content//__DB_PASSWORD__/$db_password}"
    content="${content//__MAIL_HOST__/$(env_value "$MAIL_HOST")}"
    content="${content//__MAIL_PORT__/$(env_value "$MAIL_PORT")}"
    content="${content//__MAIL_SCHEME__/$(env_value "$MAIL_SCHEME")}"
    content="${content//__MAIL_USERNAME__/$(env_value "$MAIL_USERNAME")}"
    content="${content//__MAIL_PASSWORD__/$(env_value "$MAIL_PASSWORD")}"
    content="${content//__MAIL_FROM_ADDRESS__/$(env_value "$MAIL_FROM_ADDRESS")}"
    content="${content//__LEGAL_OPERATOR_NAME__/$(env_value "$LEGAL_OPERATOR_NAME")}"
    content="${content//__LEGAL_OPERATOR_ADDRESS__/$(env_value "$LEGAL_OPERATOR_ADDRESS")}"
    content="${content//__LEGAL_CONTACT_EMAIL__/$(env_value "$LEGAL_CONTACT_EMAIL")}"

    (umask 037 && printf '%s\n' "$content" > "$env_file")
    chown "$DEPLOY_USER":www-data "$env_file"
    chmod 640 "$env_file"
    note "Wrote $env_file (the key of the application and the password of the database were made for it)."
}

install_sudoers() {
    say "Letting the deploy user do what a release needs, and nothing else"

    local file=/etc/sudoers.d/rideidle-deploy tmp
    tmp="$(mktemp)"
    cat > "$tmp" <<EOF
# RideIdle: the user that deploys may reload and restart what a release touches, and run the commands of the application
# as the user the web server runs as, so that what they write can be read and written by it.
Cmnd_Alias RIDEIDLE_SERVICES = /usr/bin/systemctl reload php${PHP_VERSION}-fpm, /usr/bin/systemctl restart rideidle-queue, /usr/bin/systemctl reload nginx
${DEPLOY_USER} ALL=(root) NOPASSWD: RIDEIDLE_SERVICES
${DEPLOY_USER} ALL=(www-data) NOPASSWD: ALL
EOF
    visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; fail "The sudoers file was not valid. Nothing was changed."; }
    install -m 440 -o root -g root "$tmp" "$file"
    rm -f "$tmp"
}

install_services() {
    say "Installing the queue worker, the scheduler and the backups"

    render "$HERE/systemd/rideidle-queue.service.template" > /etc/systemd/system/rideidle-queue.service
    systemctl daemon-reload
    # It is started by the first deploy: there is nothing for it to run before.
    systemctl enable rideidle-queue >/dev/null

    # The scheduler runs the commands that tidy up: guests that nobody returns to, expired links, mails that failed.
    cat > /etc/cron.d/rideidle <<EOF
# RideIdle scheduler
* * * * * www-data /usr/bin/php${PHP_VERSION} ${APP_DIR}/current/api/artisan schedule:run >> /dev/null 2>&1
EOF
    chmod 644 /etc/cron.d/rideidle

    install -m 755 "$HERE/bin/rideidle-backup" /usr/local/sbin/rideidle-backup
    cat > /etc/cron.d/rideidle-backup <<'EOF'
# RideIdle: a copy of the database every night
15 3 * * * root /usr/local/sbin/rideidle-backup >> /var/log/rideidle-backup.log 2>&1
EOF
    chmod 644 /etc/cron.d/rideidle-backup

    cat > /etc/logrotate.d/rideidle-backup <<'EOF'
/var/log/rideidle-backup.log {
    monthly
    rotate 3
    compress
    missingok
    notifempty
}
EOF
}

install_nginx() {
    say "Installing nginx"
    apt-get install -y -qq nginx certbot >/dev/null

    install -m 644 "$HERE/nginx/rideidle-global.conf" /etc/nginx/conf.d/rideidle-global.conf
    install -d -m 755 /etc/nginx/snippets
    install -m 644 "$HERE/nginx/rideidle-security.conf" /etc/nginx/snippets/rideidle-security.conf
    rm -f /etc/nginx/sites-enabled/default
}

setup_tls() {
    say "Getting a certificate for $DOMAIN"

    local cert="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"

    if [[ ! -f "$cert" ]]; then
        # Until there is a certificate, the site only answers the check of Let's Encrypt.
        cat > /etc/nginx/sites-available/idlerace.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 404; }
}
EOF
        ln -sf /etc/nginx/sites-available/idlerace.conf /etc/nginx/sites-enabled/idlerace.conf
        nginx -t
        systemctl enable --now nginx >/dev/null
        systemctl reload nginx

        local staging=()
        [[ "$CERTBOT_STAGING" == "1" ]] && staging=(--test-cert)

        certbot certonly --webroot -w /var/www/certbot -d "$DOMAIN" -m "$ADMIN_EMAIL" \
            --agree-tos --no-eff-email --non-interactive "${staging[@]}"
    else
        note "There is a certificate already."
    fi

    # nginx has to read the new certificate when it is renewed.
    install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

    # Now the whole site, with HTTPS.
    render "$HERE/nginx/idlerace.conf.template" > /etc/nginx/sites-available/idlerace.conf
    ln -sf /etc/nginx/sites-available/idlerace.conf /etc/nginx/sites-enabled/idlerace.conf
    nginx -t
    systemctl reload nginx

    if systemctl list-timers --no-pager 2>/dev/null | grep -q certbot; then
        note "Certificates are renewed by the timer of certbot."
    else
        note "Check that certificates are renewed: certbot renew --dry-run"
    fi
}

setup_firewall() {
    say "Setting up the firewall (SSH on port ${SSH_PORT}, HTTP and HTTPS)"
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    ufw allow "${SSH_PORT}/tcp" >/dev/null
    ufw allow 80/tcp >/dev/null
    ufw allow 443/tcp >/dev/null
    ufw --force enable >/dev/null

    systemctl enable --now fail2ban >/dev/null
}

finish() {
    say "Done"
    cat <<EOF

  Domain            https://${DOMAIN}   (nothing to see until the first deploy)
  The game lives in ${APP_DIR}
  The .env is       ${APP_DIR}/shared/.env
  The user that deploys is '${DEPLOY_USER}'. It has the same SSH key as root.

  What is left to do:
EOF

    local env_file="$APP_DIR/shared/.env"
    local todo=0
    if ! env_is_set MAIL_HOST "$env_file"; then
        echo "   - Set MAIL_HOST, MAIL_USERNAME and MAIL_PASSWORD in the .env: without them the links in e-mails are not sent."
        todo=1
    fi
    if ! env_is_set LEGAL_CONTACT_EMAIL "$env_file"; then
        echo "   - Set LEGAL_OPERATOR_NAME, LEGAL_OPERATOR_ADDRESS and LEGAL_CONTACT_EMAIL in the .env: the terms and the privacy policy show them."
        todo=1
    fi
    echo "   - Fill the keys of Google, Facebook and Apple in the .env when you have them (deploy/README.md says where)."
    echo "   - Deploy the application from your computer:   deploy/upload.sh release ${DEPLOY_USER}@${DOMAIN}"
    echo "   - Copy /var/backups/rideidle to another place now and then: a copy that is only on this server does not survive it."
    [[ "$todo" == "1" ]] && echo && echo "  (The .env can be edited now; a change takes effect with the next deploy.)"
    echo
}

check_system
check_dns
ensure_swap
install_base
install_php
install_composer
install_node
install_postgres
install_redis
create_users_and_folders
write_env
install_sudoers
install_services
install_nginx
setup_tls
setup_firewall
finish
