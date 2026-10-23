#!/usr/bin/env bash
# Sends RideIdle from your computer to the server. It needs ssh and git, nothing else (no rsync), so it works in Git Bash on
# Windows as well as on Linux and macOS. Run it from anywhere inside the repository.
#
#   deploy/upload.sh bootstrap USER@SERVER        puts the deploy/ folder on a new server, ready for provision.sh (once).
#                                                 USER is root or the user with sudo that the host gives you (often ubuntu)
#   deploy/upload.sh release deploy@SERVER [REF]  deploys the committed state of REF (default HEAD) as a new release
#   deploy/upload.sh rollback deploy@SERVER       goes back to the previous release
#
# What is sent is what is COMMITTED (git archive): not what is in your working folder, so what you test is what goes live, and
# files that are not in the repository (the .env, node_modules, vendor) never go to the server.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/rideidle}"
COMMAND="${1:-}"
TARGET="${2:-}"

usage() {
    sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

[[ -n "$COMMAND" && -n "$TARGET" ]] || usage 1

cd "$(git rev-parse --show-toplevel)"

case "$COMMAND" in
    bootstrap)
        REF="${3:-HEAD}"
        # In the home folder of whoever logs in (root: /root), so that no special rights are needed to put it there.
        echo "==> Sending deploy/ to $TARGET:~/rideidle-deploy"
        git archive --format=tar "$REF" deploy | ssh "$TARGET" 'set -e; rm -rf rideidle-deploy; mkdir -p rideidle-deploy; tar -xf - -C rideidle-deploy --strip-components=1'
        cat <<EOF

Now, on the server (ssh $TARGET):

  cd ~/rideidle-deploy
  sudo env DOMAIN=raceidle.cerasusdigital.pl ADMIN_EMAIL=you@example.com bash provision.sh

(deploy/README.md lists the other settings: the SMTP server, who runs the game ...)
EOF
        ;;

    release)
        REF="${3:-HEAD}"
        SHA="$(git rev-parse --short "$REF")"

        if ! git diff --quiet HEAD -- 2>/dev/null; then
            echo "warning: you have changes that are not committed. They are not sent: only $REF ($SHA) is." >&2
        fi

        echo "==> Sending $REF ($SHA) to $TARGET"
        # The folder is meant to be expanded here, on this computer: it is the same on the server.
        # shellcheck disable=SC2029
        git archive --format=tar "$REF" | ssh "$TARGET" "set -e; d=${APP_DIR}/incoming; rm -rf \"\$d\"; mkdir -p \"\$d\"; tar -xf - -C \"\$d\""

        echo "==> Deploying"
        ssh -t "$TARGET" "${APP_DIR}/bin/deploy.sh --source ${APP_DIR}/incoming --label ${SHA}"
        ;;

    rollback)
        ssh -t "$TARGET" "${APP_DIR}/bin/deploy.sh --rollback"
        ;;

    *)
        usage 1
        ;;
esac
