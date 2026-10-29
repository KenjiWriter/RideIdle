#!/usr/bin/env bash
# Tests of deploy/bin/rideidle-domains, the worker that carries out the jobs of the panel (put a domain on the server, look at its certificate).
#
# It runs the real script against stand-ins in a temporary folder, so it needs no root, no nginx, no certbot and no database: a `php` that serves a queue of jobs from a
# file and writes down what is reported, a `flock` and a `runuser` that do what the real ones do for the worker, a game-site.sh that says something and exits with the code
# it is told to, and a folder of certificates with a real certificate (made with openssl; the tests that need it are skipped when there is no openssl). It does not test
# game-site.sh itself, nor a real certbot, nor the ownership check of the script that runs as root (that needs root).
#
#   bash deploy/test/test-rideidle-domains.sh
#
# It exits 0 when every check passed.

set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKER="$HERE/../bin/rideidle-domains"

[[ -f "$WORKER" ]] || { echo "test: $WORKER is missing" >&2; exit 2; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

PASS=0
FAIL=0
SKIP=0
TAB=$'\t'

ok() { PASS=$((PASS + 1)); }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL: %s\n' "$1"; }
skip() { SKIP=$((SKIP + 1)); printf 'skipped: %s\n' "$1"; }
eq() {
    if [[ "$2" == "$3" ]]; then ok; else bad "$1: expected [$3], got [$2]"; fi
}
contains() {
    if [[ "$2" == *"$3"* ]]; then ok; else bad "$1: [$2] does not contain [$3]"; fi
}
lacks() {
    if [[ "$2" != *"$3"* ]]; then ok; else bad "$1: [$2] contains [$3]"; fi
}

# ---------------------------------------------------------------------------------------------------------------------- the stand-ins

mkdir -p "$T/bin" "$T/release/api" "$T/release/deploy" "$T/le/live" "$T/tmp"

# php artisan domains:claim | domains:report. The queue is a file of lines (the jobs); what is reported is written to $T/reports, and the log that came with it is kept as
# $T/report-N.log (the worker removes its own).
cat > "$T/bin/php" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "artisan" ]] || exit 99
shift
command="${1:-}"
shift || true
pwd >> "$FAKE_DIR/cwd"
case "$command" in
    domains:claim)
        echo claim >> "$FAKE_DIR/calls"
        if [[ -n "${FAKE_CLAIM_FAIL:-}" ]]; then
            echo "the database is not there" >&2
            exit 1
        fi
        if [[ -s "$FAKE_DIR/queue" ]]; then
            head -n 1 "$FAKE_DIR/queue"
            tail -n +2 "$FAKE_DIR/queue" > "$FAKE_DIR/queue.next"
            mv "$FAKE_DIR/queue.next" "$FAKE_DIR/queue"
        fi
        ;;
    domains:report)
        id="${1:-}"
        shift || true
        code="" log="" expires=""
        for argument in "$@"; do
            case "$argument" in
                --exit-code=*) code="${argument#*=}" ;;
                --log-file=*) log="${argument#*=}" ;;
                --expires=*) expires="${argument#*=}" ;;
                *) echo "unexpected argument: $argument" >> "$FAKE_DIR/calls"; exit 98 ;;
            esac
        done
        echo report >> "$FAKE_DIR/calls"
        number=$(( $(wc -l < "$FAKE_DIR/reports" 2>/dev/null || echo 0) + 1 ))
        [[ -r "$log" ]] && cp "$log" "$FAKE_DIR/report-$number.log"
        printf '%s|%s|%s\n' "$id" "$code" "$expires" >> "$FAKE_DIR/reports"
        [[ -z "${FAKE_REPORT_FAIL:-}" ]] || exit 1
        ;;
    *)
        exit 97
        ;;
esac
EOF

# flock -n -E CODE LOCKFILE COMMAND...: the command runs when the lock is free, and CODE is the answer when it is not (a folder is the lock: making it is atomic).
cat > "$T/bin/flock" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "-n" && "${2:-}" == "-E" ]] || exit 98
conflict="$3"
lock="$4"
shift 4
mkdir "$lock.d" 2>/dev/null || exit "$conflict"
"$@"
status=$?
rmdir "$lock.d" 2>/dev/null
exit "$status"
EOF

# runuser -u USER -- COMMAND...: the user is written down, and the command runs.
cat > "$T/bin/runuser" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "-u" && "${3:-}" == "--" ]] || exit 98
echo "runuser:$2" >> "$FAKE_DIR/calls"
shift 3
exec "$@"
EOF

# game-site.sh: writes down what it was given and says something.
cat > "$T/release/deploy/game-site.sh" <<'EOF'
#!/usr/bin/env bash
{
    echo "GAME_DOMAIN=${GAME_DOMAIN-unset}"
    echo "GAME_EMAIL=${GAME_EMAIL-unset}"
    echo "CERTBOT_STAGING=${CERTBOT_STAGING-unset}"
    echo "SKIP_DNS_CHECK=${SKIP_DNS_CHECK-unset}"
    echo "APP_DIR=${APP_DIR-unset}"
} >> "$FAKE_DIR/site-env"
echo run >> "$FAKE_DIR/site-runs"
echo "fake game-site says hello for ${GAME_DOMAIN-nothing}"
[[ -z "${FAKE_SITE_SLEEP:-}" ]] || sleep "$FAKE_SITE_SLEEP"
exit "${FAKE_SITE_EXIT:-0}"
EOF

chmod +x "$T/bin/php" "$T/bin/flock" "$T/bin/runuser" "$T/release/deploy/game-site.sh"

# A certificate that ends in forty days, for good.example.com.
HAVE_CERT=0
if command -v openssl >/dev/null 2>&1; then
    mkdir -p "$T/le/live/good.example.com"
    # (MSYS2_ARG_CONV_EXCL keeps Git Bash on Windows from turning the subject /CN=... into a path.)
    if MSYS2_ARG_CONV_EXCL='/CN' openssl req -x509 -newkey rsa:2048 -nodes -keyout "$T/key.pem" -out "$T/le/live/good.example.com/cert.pem" -days 40 -subj "/CN=good.example.com" >/dev/null 2>&1 \
        && [[ -s "$T/le/live/good.example.com/cert.pem" ]]; then
        HAVE_CERT=1
    fi
fi

# ---------------------------------------------------------------------------------------------------------------------- helpers

# A clean state before a case.
new_case() {
    rm -f "$T/queue" "$T/reports" "$T/calls" "$T/cwd" "$T/site-env" "$T/site-runs" "$T"/report-*.log "$T/out" "$T/err" "$T/rc"
    rm -rf "$T/lock.d" "$T/tmp"
    mkdir -p "$T/tmp"
    : > "$T/queue"
}

# Adds a job to the queue: the four fields with a tab between them, as `domains:claim` prints them.
job() {
    printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" >> "$T/queue"
}

# Runs the worker as the tests do (no root, no switching of the user), with more settings given as NAME=value. The answer is in $T/out, $T/err and $T/rc.
run_worker() {
    env SKIP_ROOT_CHECK=1 RUN_AS_NONE=1 APP_DIR="$T/release" PHP_BIN="$T/bin/php" GAME_SITE="$T/release/deploy/game-site.sh" LETSENCRYPT_DIR="$T/le" \
        DOMAIN_WORKER_LOCK="$T/lock" TMPDIR="$T/tmp" FAKE_DIR="$T" PATH="$T/bin:$PATH" "$@" \
        timeout 120 bash "$WORKER" > "$T/out" 2> "$T/err"
    echo $? > "$T/rc"
}

rc() { cat "$T/rc"; }
out() { cat "$T/out"; }
err() { cat "$T/err"; }
reports() { cat "$T/reports" 2>/dev/null || true; }
site_runs() { if [[ -f "$T/site-runs" ]]; then wc -l < "$T/site-runs" | tr -d ' '; else echo 0; fi; }
count_calls() { if [[ -f "$T/calls" ]]; then grep -c "^$1" "$T/calls" || true; else echo 0; fi; }
leftovers() { find "$T/tmp" -type f 2>/dev/null | wc -l | tr -d ' '; }

today_plus() { date -u -d "+$1 days" +%Y-%m-%d; }

# ---------------------------------------------------------------------------------------------------------------------- the cases

echo "no job"
new_case
run_worker
eq "no job: exit code" "$(rc)" "0"
eq "no job: nothing reported" "$(reports)" ""
eq "no job: game-site not run" "$(site_runs)" "0"
contains "no job: it says so" "$(out)" "0 job(s) handled"
eq "no job: one claim" "$(count_calls claim)" "1"

echo "a good provision job"
new_case
job 7 provision good.example.com ops@example.com
run_worker
eq "provision: exit code" "$(rc)" "0"
eq "provision: game-site run once" "$(site_runs)" "1"
eq "provision: GAME_DOMAIN" "$(grep '^GAME_DOMAIN=' "$T/site-env")" "GAME_DOMAIN=good.example.com"
eq "provision: GAME_EMAIL" "$(grep '^GAME_EMAIL=' "$T/site-env")" "GAME_EMAIL=ops@example.com"
eq "provision: staging not given on" "$(grep '^CERTBOT_STAGING=' "$T/site-env")" "CERTBOT_STAGING=unset"
eq "provision: the DNS check not skipped" "$(grep '^SKIP_DNS_CHECK=' "$T/site-env")" "SKIP_DNS_CHECK=unset"
eq "provision: the APP_DIR of the worker is not given on" "$(grep '^APP_DIR=' "$T/site-env")" "APP_DIR=unset"
if [[ $HAVE_CERT -eq 1 ]]; then
    expires="$(reports | cut -d'|' -f3)"
    eq "provision: reported done for job 7" "$(reports | cut -d'|' -f1,2)" "7|0"
    if [[ "$expires" == "$(today_plus 39)" || "$expires" == "$(today_plus 40)" || "$expires" == "$(today_plus 41)" ]]; then ok; else bad "provision: the end of the certificate is about forty days away, got [$expires]"; fi
else
    eq "provision: reported done for job 7" "$(reports)" "7|0|"
    skip "provision: the end of the certificate (no openssl)"
fi
contains "provision: the log has what game-site said" "$(cat "$T/report-1.log")" "fake game-site says hello for good.example.com"
eq "provision: artisan runs in the folder of the application" "$(sort -u "$T/cwd" | head -n 1)" "$(cd "$T/release/api" && pwd)"
eq "provision: no log left behind" "$(leftovers)" "0"

echo "game-site fails"
new_case
job 8 provision nocert.example.com ops@example.com
run_worker FAKE_SITE_EXIT=3
eq "failing: the worker itself exits 0" "$(rc)" "0"
eq "failing: reported with its exit code, with no certificate" "$(reports)" "8|3|"
contains "failing: the log has what it said" "$(cat "$T/report-1.log")" "hello"

echo "what is given on to game-site.sh"
new_case
job 9 provision nocert.example.com ops@example.com
run_worker CERTBOT_STAGING=1 SKIP_DNS_CHECK=1
eq "passed on: staging" "$(grep '^CERTBOT_STAGING=' "$T/site-env")" "CERTBOT_STAGING=1"
eq "passed on: the DNS check" "$(grep '^SKIP_DNS_CHECK=' "$T/site-env")" "SKIP_DNS_CHECK=1"
new_case
job 9 provision nocert.example.com ops@example.com
run_worker CERTBOT_STAGING=yes "SKIP_DNS_CHECK=1;ls"
eq "not passed on: a value that is not 0 or 1 (staging)" "$(grep '^CERTBOT_STAGING=' "$T/site-env")" "CERTBOT_STAGING=unset"
eq "not passed on: a value that is not 0 or 1 (DNS)" "$(grep '^SKIP_DNS_CHECK=' "$T/site-env")" "SKIP_DNS_CHECK=unset"

echo "a job whose domain is not in the shape of a host name is refused and never run"
new_case
long_label="$(printf 'a%.0s' $(seq 1 64))"
too_long="$(printf 'a%.0s' $(seq 1 63)).$(printf 'b%.0s' $(seq 1 63)).$(printf 'c%.0s' $(seq 1 63)).$(printf 'd%.0s' $(seq 1 63)).com"
declare -a bad_domains=(
    "bad domain.example.com"
    "evil.example.com;ls"
    "evil.example.com&&id"
    '$(id).example.com'
    '`id`.example.com'
    "../etc/passwd"
    "a/b.example.com"
    "a\\b.example.com"
    "Good.Example.com"
    "localhost"
    "${long_label}.example.com"
    "${too_long}"
    ""
    "-bad.example.com"
    "bad-.example.com"
    "bad..example.com"
    ".bad.example.com"
    "bad.example.com."
    "bad_underscore.example.com"
    "bad.exam*ple.com"
    "bad.example.com -d other.com"
)
id=100
for domain in "${bad_domains[@]}"; do
    job "$id" provision "$domain" ops@example.com
    id=$((id + 1))
done
run_worker DOMAIN_WORKER_MAX_JOBS=100
eq "bad domains: exit code" "$(rc)" "0"
eq "bad domains: game-site never run" "$(site_runs)" "0"
eq "bad domains: every job was reported" "$(reports | wc -l | tr -d ' ')" "${#bad_domains[@]}"
eq "bad domains: every job was reported as failed with 2" "$(reports | cut -d'|' -f2 | sort -u)" "2"
eq "bad domains: with no certificate date" "$(reports | cut -d'|' -f3 | sort -u)" ""
contains "bad domains: the log says which field" "$(cat "$T/report-1.log")" "domain"
eq "bad domains: no log left behind" "$(leftovers)" "0"

echo "a job whose e-mail, action or domain is not right"
new_case
declare -a bad_jobs=(
    "provision|good.example.com|a b@example.com"
    "provision|good.example.com|a@example.com;rm -rf /"
    "provision|good.example.com|-x@example.com"
    "provision|good.example.com|"
    "provision|good.example.com|no-at-sign"
    "provision|good.example.com|a@@example.com"
    "provision|good.example.com|a@example"
    "provision|good.example.com|a@exa mple.com"
    'provision|good.example.com|$(id)@example.com'
    "check|good.example.com|a b@example.com"
    "rm|good.example.com|ops@example.com"
    "|good.example.com|ops@example.com"
    "provision;ls|good.example.com|ops@example.com"
    "PROVISION|good.example.com|ops@example.com"
)
id=200
for spec in "${bad_jobs[@]}"; do
    IFS='|' read -r action domain email <<<"$spec"
    job "$id" "$action" "$domain" "$email"
    id=$((id + 1))
done
run_worker DOMAIN_WORKER_MAX_JOBS=100
eq "bad jobs: exit code" "$(rc)" "0"
eq "bad jobs: game-site never run" "$(site_runs)" "0"
eq "bad jobs: every job was reported" "$(reports | wc -l | tr -d ' ')" "${#bad_jobs[@]}"
eq "bad jobs: every job was reported as failed with 2" "$(reports | cut -d'|' -f2 | sort -u)" "2"
contains "bad jobs: the e-mail is named in a log" "$(cat "$T/report-1.log")" "e-mail"

echo "a job without an id that is a number, or a line that is not four fields, stops the run and runs nothing"
for spec in "1;ls" "" "abc" "1 2" "99999999999999999999"; do
    new_case
    job "$spec" provision good.example.com ops@example.com
    run_worker
    eq "bad id [$spec]: the run stops with 1" "$(rc)" "1"
    eq "bad id [$spec]: game-site not run" "$(site_runs)" "0"
    eq "bad id [$spec]: nothing reported (there is no job to say it about)" "$(reports)" ""
    contains "bad id [$spec]: it says why" "$(err)" "not a number"
done
new_case
printf '5\tprovision\tgood.example.com\n' >> "$T/queue"
run_worker
eq "three fields: the run stops with 1" "$(rc)" "1"
eq "three fields: game-site not run" "$(site_runs)" "0"
contains "three fields: it says why" "$(err)" "four fields"
new_case
printf '5\tprovision\tgood.example.com\tops@example.com\textra\n' >> "$T/queue"
run_worker
eq "five fields: the run stops with 1" "$(rc)" "1"
eq "five fields: game-site not run" "$(site_runs)" "0"
new_case
printf '5 provision good.example.com ops@example.com\n' >> "$T/queue"
run_worker
eq "spaces for tabs: the run stops with 1" "$(rc)" "1"
eq "spaces for tabs: game-site not run" "$(site_runs)" "0"

echo "the certificate of a domain"
new_case
job 20 check good.example.com ""
run_worker
eq "check with a certificate: exit code" "$(rc)" "0"
eq "check with a certificate: game-site not run" "$(site_runs)" "0"
if [[ $HAVE_CERT -eq 1 ]]; then
    eq "check with a certificate: reported done" "$(reports | cut -d'|' -f1,2)" "20|0"
    contains "check with a certificate: the log says when it ends" "$(cat "$T/report-1.log")" "ends on $(reports | cut -d'|' -f3)"
else
    skip "check with a certificate (no openssl)"
fi
new_case
job 21 check nocert.example.com ""
run_worker
eq "check without a certificate: reported failed with 1, no date" "$(reports)" "21|1|"
contains "check without a certificate: the log says so" "$(cat "$T/report-1.log")" "There is no certificate for nocert.example.com."
eq "check without a certificate: game-site not run" "$(site_runs)" "0"

echo "a dry run"
new_case
job 30 provision good.example.com ops@example.com
run_worker DOMAIN_WORKER_DRY_RUN=1
eq "dry run: exit code" "$(rc)" "0"
eq "dry run: game-site not run" "$(site_runs)" "0"
eq "dry run: reported done, with no date of a certificate" "$(reports)" "30|0|"
contains "dry run: the log says it was one" "$(cat "$T/report-1.log")" "dry run"
contains "dry run: the log says what would have been run" "$(cat "$T/report-1.log")" "GAME_DOMAIN=good.example.com"

echo "a job that takes too long"
new_case
job 40 provision good.example.com ops@example.com
started=$SECONDS
run_worker FAKE_SITE_SLEEP=30 DOMAIN_WORKER_TIMEOUT=1
took=$((SECONDS - started))
eq "timeout: the worker itself exits 0" "$(rc)" "0"
eq "timeout: reported failed with 124 (or 137 when it had to be killed)" "$([[ "$(reports | cut -d'|' -f2)" == "124" || "$(reports | cut -d'|' -f2)" == "137" ]] && echo yes || echo no)" "yes"
if [[ $took -lt 25 ]]; then ok; else bad "timeout: the job was stopped after the timeout, not after 30 seconds (took $took)"; fi
contains "timeout: the log says it was stopped" "$(cat "$T/report-1.log")" "did not finish in 1 seconds"

echo "how many jobs a run takes"
new_case
for number in 1 2 3 4 5 6 7; do job "$((300 + number))" provision nocert.example.com ops@example.com; done
run_worker DOMAIN_WORKER_MAX_JOBS=3
eq "max jobs: three were reported" "$(reports | wc -l | tr -d ' ')" "3"
eq "max jobs: the oldest first" "$(reports | cut -d'|' -f1 | tr '\n' ' ')" "301 302 303 "
eq "max jobs: the rest is left for the next run" "$(wc -l < "$T/queue" | tr -d ' ')" "4"
new_case
for number in 1 2 3 4 5 6 7; do job "$((300 + number))" provision nocert.example.com ops@example.com; done
run_worker
eq "max jobs: five by default" "$(reports | wc -l | tr -d ' ')" "5"

echo "two runs at once"
new_case
job 50 provision good.example.com ops@example.com
env SKIP_ROOT_CHECK=1 RUN_AS_NONE=1 APP_DIR="$T/release" PHP_BIN="$T/bin/php" GAME_SITE="$T/release/deploy/game-site.sh" LETSENCRYPT_DIR="$T/le" \
    DOMAIN_WORKER_LOCK="$T/lock" TMPDIR="$T/tmp" FAKE_DIR="$T" PATH="$T/bin:$PATH" FAKE_SITE_SLEEP=4 \
    timeout 120 bash "$WORKER" > "$T/first.out" 2> "$T/first.err" &
first=$!
sleep 1.5
run_worker
eq "lock: the second run exits 0" "$(rc)" "0"
contains "lock: the second run says another is at it" "$(out)" "another run is at it"
wait "$first"
eq "lock: the first run took the job" "$(reports | cut -d'|' -f1,2 | head -n 1)" "50|0"
eq "lock: only one run of game-site" "$(site_runs)" "1"
# The first run asked twice (it got the job, and then found the queue empty); the second one did not ask at all.
eq "lock: no claim by the second run" "$(count_calls claim)" "2"
rm -f "$T/first.out" "$T/first.err"

echo "artisan runs as the user of the web server"
new_case
job 60 provision nocert.example.com ops@example.com
env SKIP_ROOT_CHECK=1 APP_DIR="$T/release" PHP_BIN="$T/bin/php" GAME_SITE="$T/release/deploy/game-site.sh" LETSENCRYPT_DIR="$T/le" WEB_USER=webby \
    DOMAIN_WORKER_LOCK="$T/lock" TMPDIR="$T/tmp" FAKE_DIR="$T" PATH="$T/bin:$PATH" \
    timeout 120 bash "$WORKER" > "$T/out" 2> "$T/err"
echo $? > "$T/rc"
eq "runuser: exit code" "$(rc)" "0"
eq "runuser: the claims and the report were made as that user" "$(grep -c '^runuser:webby$' "$T/calls")" "$(( $(count_calls claim) + $(count_calls report) ))"
eq "runuser: the job was done" "$(reports)" "60|0|"
eq "runuser: the user name is checked" "$(env SKIP_ROOT_CHECK=1 WEB_USER='x;ls' APP_DIR="$T/release" PHP_BIN="$T/bin/php" DOMAIN_WORKER_LOCK="$T/lock" PATH="$T/bin:$PATH" bash "$WORKER" >/dev/null 2>&1; echo $?)" "1"

echo "when the application does not answer"
new_case
job 70 provision nocert.example.com ops@example.com
run_worker FAKE_CLAIM_FAIL=1
eq "claim fails: the run stops with 1" "$(rc)" "1"
contains "claim fails: it says so" "$(err)" "domains:claim failed"
eq "claim fails: game-site not run" "$(site_runs)" "0"
new_case
job 71 provision nocert.example.com ops@example.com
job 72 provision nocert.example.com ops@example.com
run_worker FAKE_REPORT_FAIL=1
eq "report fails: the run goes on with the next job" "$(reports | wc -l | tr -d ' ')" "2"
eq "report fails: the run ends with 3" "$(rc)" "3"
contains "report fails: it says so" "$(err)" "could not report job 71"

echo "no release yet"
new_case
job 80 provision good.example.com ops@example.com
run_worker APP_DIR="$T/nowhere"
eq "no release: exit 0" "$(rc)" "0"
contains "no release: it says so" "$(out)" "no release is live yet"
eq "no release: nothing asked" "$(count_calls claim)" "0"

echo "a script that is not there"
new_case
job 81 provision nocert.example.com ops@example.com
run_worker GAME_SITE="$T/release/deploy/missing.sh"
eq "no game-site: reported failed with 2" "$(reports)" "81|2|"
contains "no game-site: the log says so" "$(cat "$T/report-1.log")" "does not exist"

echo "settings that do not make sense"
new_case
run_worker DOMAIN_WORKER_TIMEOUT=abc
eq "timeout setting: refused" "$(rc)" "1"
run_worker DOMAIN_WORKER_TIMEOUT=0
eq "timeout setting zero: refused" "$(rc)" "1"
run_worker DOMAIN_WORKER_MAX_JOBS=0
eq "max jobs setting zero: refused" "$(rc)" "1"
run_worker DOMAIN_WORKER_DRY_RUN=2
eq "dry run setting: refused" "$(rc)" "1"
run_worker PHP_BIN=/no/such/php
eq "no php: refused" "$(rc)" "1"
contains "no php: it says so" "$(err)" "php is missing"
if [[ $EUID -ne 0 ]]; then
    env RUN_AS_NONE=1 APP_DIR="$T/release" PHP_BIN="$T/bin/php" DOMAIN_WORKER_LOCK="$T/lock" PATH="$T/bin:$PATH" bash "$WORKER" >/dev/null 2>"$T/err"
    eq "not root: refused" "$?" "1"
    contains "not root: it says so" "$(err)" "Run this as root"
else
    skip "not root: refused (these tests run as root)"
fi

skip "the script that runs as root must belong to root alone (needs root)"
skip "a real flock, a real runuser, a real certbot and a real nginx (only stand-ins are used)"

echo
printf '%d checks passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
[[ $FAIL -eq 0 ]]
