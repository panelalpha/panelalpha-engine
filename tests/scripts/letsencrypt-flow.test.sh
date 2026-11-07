#!/bin/bash
# Runs the three Let's Encrypt scripts end to end against a scratch tree, with
# a fake `docker` standing in for compose and for the certbot container.
#
# What it guards: the served pair (crt/server.*) follows the lineage that was
# last installed on it, the IP lineage lands beside it and never replaces it
# unasked, the setting records what was issued, renewal is scheduled, and
# letsencrypt-renew.sh renews only when a lineage is inside its window,
# reinstalls only what changed, and keeps serving what was being served -- the
# gaps in the old scripts were exactly here (no cron, no reinstall, no
# nginx reload), so this is the test that would have caught them.
# Also that sites-http is down only while certbot itself runs.
#
# Needs bash, openssl and coreutils; no root, no network.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../scripts" && pwd)"
. "$(dirname "${BASH_SOURCE[0]}")/test-network.sh"
ADDRESS=$(tn_host PUBLIC 7)
DIRECT_NAME="${ADDRESS//./-}.panelalpha.direct"
SANDBOX="$(mktemp -d)"
trap 'rm -rf "$SANDBOX"' EXIT

export LE_ENGINE_DIR="$SANDBOX/engine"
export LE_LETSENCRYPT_DIR="$SANDBOX/letsencrypt"
export LE_LOG_DIR="$SANDBOX/log"
export LE_CRON_FILE="$SANDBOX/cron.d/panelalpha-letsencrypt"
export FAKE_SETTINGS_DIR="$SANDBOX/settings"
export FAKE_DOCKER_LOG="$SANDBOX/docker.log"
export FAKE_CERT_DAYS=90
mkdir -p "$LE_ENGINE_DIR/scripts" "$LE_ENGINE_DIR/crt" "$FAKE_SETTINGS_DIR" "$SANDBOX/bin" "$(dirname "$LE_CRON_FILE")"
touch "$LE_ENGINE_DIR/docker-compose.yml"
echo "APP_URL=https://$ADDRESS:2011" >"$LE_ENGINE_DIR/.env-core"
cp "$SCRIPT_DIR"/letsencrypt-*.sh "$LE_ENGINE_DIR/scripts/"

# --- the fake docker --------------------------------------------------------------

cat >"$SANDBOX/bin/docker" <<'FAKE'
#!/bin/bash
# `docker compose ...` and `docker run ... certbot/certbot ...`, logged.
echo "$*" >>"$FAKE_DOCKER_LOG"

issue() {
    # issue NAME [--ip-address ADDR | -d DOMAIN]: a self-signed stand-in laid
    # out the way certbot lays a lineage out: archive/ plus live/ symlinks.
    local name="$1" subject="$2"
    local archive="$LE_LETSENCRYPT_DIR/archive/$name" live="$LE_LETSENCRYPT_DIR/live/$name"
    mkdir -p "$archive" "$live" "$LE_LETSENCRYPT_DIR/renewal"
    local n
    n=$(( $(ls "$archive" 2>/dev/null | grep -c privkey) + 1 ))
    openssl req -x509 -newkey rsa:2048 -nodes -days "$FAKE_CERT_DAYS" \
        -subj "/CN=${subject}" -keyout "$archive/privkey${n}.pem" -out "$archive/fullchain${n}.pem" >/dev/null 2>&1
    ln -sf "../../archive/$name/fullchain${n}.pem" "$live/fullchain.pem"
    ln -sf "../../archive/$name/privkey${n}.pem" "$live/privkey.pem"
    echo "[renewalparams]" >"$LE_LETSENCRYPT_DIR/renewal/$name.conf"
}

if [ "$1" = "compose" ]; then
    shift; shift; shift   # -f FILE
    case "$1 ${2:-}" in
    "ps --services") echo core ;;
    "exec -T")
        # exec -T core php artisan settings:get|set NAME [VALUE]
        case "$6" in
        settings:get) cat "$FAKE_SETTINGS_DIR/$7" 2>/dev/null ;;
        settings:set) echo "$8" >"$FAKE_SETTINGS_DIR/$7" ;;
        esac
        ;;
    esac
    exit 0
fi

case "$1" in
image) [ "${FAKE_NO_IMAGE:-0}" = 0 ]; exit ;;   # image inspect IMAGE
pull) [ "${FAKE_PULL_FAIL:-0}" = 0 ]; exit ;;
rm) exit 0 ;;
esac

# docker run ... certbot/certbot ARGS
while [ $# -gt 0 ] && [ "$1" != "certbot/certbot" ]; do shift; done
shift
[ "${FAKE_CERTBOT_FAIL:-0}" = 1 ] && exit 1
[ "${FAKE_CERTBOT_HANG:-0}" = 1 ] && exec "$REAL_SLEEP" 30
case "$1" in
certonly)
    name=""; subject=""; dry=0
    while [ $# -gt 0 ]; do
        case "$1" in
        --cert-name) name="$2"; shift ;;
        -d | --ip-address) subject="$2"; shift ;;
        --dry-run) dry=1 ;;
        esac
        shift
    done
    [ "$dry" = 1 ] || issue "$name" "$subject"
    ;;
renew)
    for conf in "$LE_LETSENCRYPT_DIR"/renewal/*.conf; do
        name=$(basename "$conf" .conf)
        issue "$name" "renewed-$name"
    done
    ;;
esac
FAKE
chmod +x "$SANDBOX/bin/docker"

# Renewal's random delay is minutes long: logged beside docker, not slept.
cat >"$SANDBOX/bin/sleep" <<'FAKE'
#!/bin/bash
echo "sleep $*" >>"$FAKE_DOCKER_LOG"
FAKE
chmod +x "$SANDBOX/bin/sleep"
REAL_SLEEP="$(command -v sleep)"
export REAL_SLEEP

# The bound each `timeout` was given, logged before the real one runs.
export FAKE_TIMEOUT_LOG="$SANDBOX/timeout.log"
cat >"$SANDBOX/bin/timeout" <<'FAKE'
#!/bin/bash
echo "$*" >>"$FAKE_TIMEOUT_LOG"
exec "$REAL_TIMEOUT" "$@"
FAKE
chmod +x "$SANDBOX/bin/timeout"
REAL_TIMEOUT="$(command -v timeout)"
export REAL_TIMEOUT
export PATH="$SANDBOX/bin:$PATH"

failures=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; failures=$((failures + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

# The scripts insist on root because certbot needs :80; not here.
run_as_root() {
    local script="$1"; shift
    ( id() { echo 0; }; export -f id; bash "$LE_ENGINE_DIR/scripts/$script" "$@" ) >"$SANDBOX/out" 2>&1
}

same_file() { cmp -s "$1" "$2"; }
live() { echo "$LE_LETSENCRYPT_DIR/live/$1"; }
crt() { echo "$LE_ENGINE_DIR/crt/$1"; }

# --- 1. the default domain certificate ------------------------------------------------

run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
check "request-cert exits 0" "[ $? = 0 ]"
check "lineage issued for the dashed name" \
    "grep -q -- '-d $DIRECT_NAME' '$FAKE_DOCKER_LOG' && [ -f '$(live panelalpha-engine-cert)/fullchain.pem' ]"
check "account registered without an email when none is known" "grep -q -- '--register-unsafely-without-email' '$FAKE_DOCKER_LOG'"
check "served cert is the domain lineage" "same_file '$(live panelalpha-engine-cert)/fullchain.pem' '$(crt server.cert)'"
check "served key is a real file, mode 600" "[ ! -L '$(crt server.key)' ] && [ \"\$(stat -c %a '$(crt server.key)')\" = 600 ]"
check "cert_domain setting records the name" "[ \"\$(cat '$FAKE_SETTINGS_DIR/cert_domain')\" = $DIRECT_NAME ]"
check "webserver stopped for the challenge and stack restored" \
    "grep -q 'down sites-http' '$FAKE_DOCKER_LOG' && grep -q 'up -d' '$FAKE_DOCKER_LOG'"
check "certbot answers the challenge on the host network, not a published port" \
    "grep -q -- 'run .*--network host .*certbot/certbot certonly' '$FAKE_DOCKER_LOG' && ! grep -q -- '-p 80:80' '$FAKE_DOCKER_LOG'"
check "core's nginx reloaded so it loads the new pair" "grep -q 'exec -T core nginx -s reload' '$FAKE_DOCKER_LOG'"
check "the new key announced to PanelAlpha Connect before the reload" \
    "grep -n -e 'hub:tls-announce /root/crt/server.cert' -e 'nginx -s reload' '$FAKE_DOCKER_LOG' | head -1 | grep -q hub:tls-announce"
check "core is never restarted" "! grep -qE 'restart( [a-z-]+)* core( |\$)' '$FAKE_DOCKER_LOG'"
check "queue workers told to reread APP_URL" "grep -q 'exec -T core php artisan queue:restart' '$FAKE_DOCKER_LOG'"
check "renewal scheduled" "grep -q 'letsencrypt-renew.sh' '$LE_CRON_FILE'"
check "APP_URL moved off the bare IP onto the served name" \
    "grep -qx 'APP_URL=https://$DIRECT_NAME:2011' '$LE_ENGINE_DIR/.env-core'"

# --- 2. the setting drives the next request ----------------------------------------------

echo "panel.example.com" >"$FAKE_SETTINGS_DIR/cert_domain"
echo "ops@example.com" >"$FAKE_SETTINGS_DIR/cert_email"
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
check "cert_domain setting is requested when no argument is given" "grep -q -- '-d panel.example.com' '$FAKE_DOCKER_LOG'"
check "cert_email setting registers the account" "grep -q -- '--email ops@example.com' '$FAKE_DOCKER_LOG'"
check "APP_URL follows a changed cert_domain" "grep -qx 'APP_URL=https://panel.example.com:2011' '$LE_ENGINE_DIR/.env-core'"

echo "APP_URL=https://ops.example.net:2011" >"$LE_ENGINE_DIR/.env-core"
run_as_root letsencrypt-request-cert.sh --domain another.example.com --ip "$ADDRESS" --skip-dns-check
check "an operator's own APP_URL is left alone" "grep -qx 'APP_URL=https://ops.example.net:2011' '$LE_ENGINE_DIR/.env-core'"

# --- 3. the IP lineage lands beside, not over -------------------------------------------------

served_before=$(md5sum <"$(crt server.cert)")
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS"
check "request-ip-cert exits 0" "[ $? = 0 ]"
check "IP lineage requested under the short-lived profile" \
    "grep -q -- '--required-profile shortlived --ip-address $ADDRESS' '$FAKE_DOCKER_LOG'"
check "IP cert installed as crt/server-ip.*" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server-ip.cert)'"
check "served pair untouched by the IP request" "[ \"\$(md5sum <'$(crt server.cert)')\" = '$served_before' ]"

: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" --install
check "--install serves the IP cert" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)'"

# --- 4. a failed request leaves the served pair alone ---------------------------------------------

served_before=$(md5sum <"$(crt server.cert)")
FAKE_CERTBOT_FAIL=1 run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check --force-renewal
check "certbot failure is reported" "[ $? != 0 ]"
check "served pair unchanged after a failed request" "[ \"\$(md5sum <'$(crt server.cert)')\" = '$served_before' ]"

# --- 5. renewal ---------------------------------------------------------------------------------

# Put the domain lineage back on :2011 first.
run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-renew.sh
check "fresh lineages: renew does not stop the webserver" \
    "! grep -q 'renew' '$FAKE_DOCKER_LOG' && ! grep -q 'down sites-http' '$FAKE_DOCKER_LOG'"
check "fresh lineages: nothing reinstalled" "! grep -q 'nginx -s reload' '$FAKE_DOCKER_LOG'"

# A lineage inside its window: renewal runs and the served pair follows.
FAKE_CERT_DAYS=6 run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" --force-renewal
: >"$FAKE_DOCKER_LOG"
served_before=$(md5sum <"$(crt server.cert)")
# 61 days on: the 90-day domain cert has 29 left (< 30), the 6-day one is gone.
(
    date() {
        if [ "$1" = "+%s" ]; then echo $(( $(command date +%s) + 61 * 86400 )); else command date "$@"; fi
    }
    id() { echo 0; }
    source "$LE_ENGINE_DIR/scripts/letsencrypt-renew.sh"
    main
) >"$SANDBOX/out" 2>&1
check "due lineage: certbot renew runs with the webserver stopped" \
    "grep -q 'certbot/certbot renew' '$FAKE_DOCKER_LOG' && grep -q 'down sites-http' '$FAKE_DOCKER_LOG'"
check "renewed domain cert is reinstalled and served" \
    "[ \"\$(md5sum <'$(crt server.cert)')\" != '$served_before' ] && same_file '$(live panelalpha-engine-cert)/fullchain.pem' '$(crt server.cert)'"
check "renewed IP cert refreshed beside it" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server-ip.cert)'"
check "core's nginx reloaded after reinstall" "grep -q 'exec -T core nginx -s reload' '$FAKE_DOCKER_LOG'"

# --- 6. renewal falls back to the IP lineage when there is no domain one ------------------------------

rm -rf "$LE_LETSENCRYPT_DIR/live/panelalpha-engine-cert" "$LE_LETSENCRYPT_DIR/renewal/panelalpha-engine-cert.conf"
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-renew.sh --always
check "no domain lineage: the IP lineage is served" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)'"

# --- 7. renewal serves what is served, not simply the domain lineage ----------------------------

# Both lineages exist and the IP certificate is the one on :2011 -- the shape
# left behind when a later domain request fails, or when an administrator goes
# back to the address. Renewal refreshes it in place and does not quietly put
# the domain certificate back on the port.
run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" --install
check "setup: the IP cert is served while a domain lineage exists" \
    "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)' && [ -f '$(live panelalpha-engine-cert)/fullchain.pem' ]"

: >"$FAKE_DOCKER_LOG"
served_before=$(md5sum <"$(crt server.cert)")
run_as_root letsencrypt-renew.sh --always
check "renewal keeps serving the IP lineage rather than restoring the domain one" \
    "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)'"
check "the renewed IP cert did reach the served pair" \
    "[ \"\$(md5sum <'$(crt server.cert)')\" != '$served_before' ]"

# --- 8. the webserver is down only while certbot runs ----------------------------------------

# No image pull and no random delay after the stop. Cron runs it with no terminal.
renew() { bash "$LE_ENGINE_DIR/scripts/letsencrypt-renew.sh" --always </dev/null >"$SANDBOX/out" 2>&1; }
at() { grep -n -m1 -- "$1" "$FAKE_DOCKER_LOG" | cut -d: -f1; }
before() { local a b; a=$(at "$1"); b=$(at "$2"); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]; }
next_after() { sed -n "$(($(at "$1") + 1))p" "$FAKE_DOCKER_LOG" | grep -q -- "$2"; }

: >"$FAKE_DOCKER_LOG"
FAKE_NO_IMAGE=1 renew
check "a missing certbot image is pulled before the webserver stops" "before 'pull -q certbot/certbot' 'down sites-http'"
check "certbot itself never pulls" "grep -q -- 'run .*--pull never .*certbot/certbot renew' '$FAKE_DOCKER_LOG'"
check "the random delay is taken before the webserver stops" "before '^sleep [0-9]' 'down sites-http'"
check "certbot is told not to take it again" "grep -q -- 'certbot/certbot renew --non-interactive --no-random-sleep-on-renew' '$FAKE_DOCKER_LOG'"
check "the stack comes back as soon as certbot exits" "next_after 'certbot/certbot renew' 'up -d'"

: >"$FAKE_DOCKER_LOG"
FAKE_NO_IMAGE=1 FAKE_PULL_FAIL=1 renew
check "no image and no pull: the webserver is not stopped" \
    "! grep -q 'down sites-http' '$FAKE_DOCKER_LOG' && ! grep -q 'certbot/certbot renew' '$FAKE_DOCKER_LOG'"
: >"$FAKE_DOCKER_LOG"
FAKE_NO_IMAGE=1 FAKE_PULL_FAIL=1 run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
check "a request without the image fails before stopping the webserver" \
    "[ $? != 0 ] && ! grep -q 'down sites-http' '$FAKE_DOCKER_LOG'"

: >"$FAKE_DOCKER_LOG"
started=$(date +%s)
FAKE_CERTBOT_HANG=1 LE_CERTBOT_TIMEOUT=2 renew
check "a hanging certbot is killed after LE_CERTBOT_TIMEOUT" \
    "[ $(($(date +%s) - started)) -lt 10 ] && next_after 'certbot/certbot renew' 'rm -f certbot-engine'"
check "and the stack comes back" "next_after 'rm -f certbot-engine' 'up -d'"

# Stopped mid-renewal (a closed SSH session, a kill): the stack still comes back.
: >"$FAKE_DOCKER_LOG"
# The script itself, not renew(): $! of a backgrounded function is its subshell.
FAKE_CERTBOT_HANG=1 LE_CERTBOT_TIMEOUT=3 \
    bash "$LE_ENGINE_DIR/scripts/letsencrypt-renew.sh" --always </dev/null >"$SANDBOX/out" 2>&1 &
pid=$!
for _ in $(seq 100); do
    grep -q 'certbot/certbot renew' "$FAKE_DOCKER_LOG" && break
    "$REAL_SLEEP" 0.1
done
kill -TERM "$pid"
wait "$pid"
status=$?
check "a renewal stopped by a signal still restores the stack" \
    "[ $status != 0 ] && before 'certbot/certbot renew' 'up -d'"

# --- 9. a first issuance is given longer than a renewal ------------------------------------

# Cron retries a renewal cut off at its bound; nothing retries a first request.
certbot_bound() { grep -- ' docker run ' "$FAKE_TIMEOUT_LOG" | tail -1 | cut -d' ' -f3; }

run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
check "a domain request gives certbot 600s" "[ \"\$(certbot_bound)\" = 600 ]"
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS"
check "an IP request gives certbot 600s" "[ \"\$(certbot_bound)\" = 600 ]"
renew
check "a renewal keeps its 120s" "[ \"\$(certbot_bound)\" = 120 ]"
LE_CERTBOT_TIMEOUT=45 run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check
check "a request keeps the bound its caller set" "[ \"\$(certbot_bound)\" = 45 ]"

# --- 10. the webserver stops only for a certificate certbot will issue -------------------------

# An update on a named host requests both lineages; with neither due, sites-http must not stop.
update() {
    run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" && run_as_root letsencrypt-request-cert.sh --ip="$ADDRESS" --skip-dns-check
}
days_on() { # DAYS SCRIPT [ARGS...]: run as root that many days from now
    local days="$1" script="$2"
    shift 2
    (
        id() { echo 0; }
        date() { if [ "$1" = "+%s" ]; then echo $(($(command date +%s) + FAKE_DAYS_ON * 86400)); else command date "$@"; fi; }
        export -f id date
        FAKE_DAYS_ON="$days" bash "$LE_ENGINE_DIR/scripts/$script" "$@"
    ) >"$SANDBOX/out" 2>&1
}

run_as_root letsencrypt-request-cert.sh --domain panel.example.com --ip "$ADDRESS" --skip-dns-check
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" --force-renewal
served_before=$(md5sum <"$(crt server.cert)")
rm -f "$LE_CRON_FILE"
: >"$FAKE_DOCKER_LOG"
update
check "nothing due: the update's requests succeed" "[ $? = 0 ]"
check "without asking certbot" "! grep -q 'certbot/certbot' '$FAKE_DOCKER_LOG'"
check "or stopping sites-http and bringing the stack back" \
    "! grep -q 'down sites-http' '$FAKE_DOCKER_LOG' && ! grep -q 'up -d' '$FAKE_DOCKER_LOG'"
check "and say why" "grep -q 'The certificate for panel.example.com is not yet due for renewal' '$SANDBOX/out'"
check "the served certificate stays the domain one" \
    "[ \"\$(md5sum <'$(crt server.cert)')\" = '$served_before' ] && same_file '$(live panelalpha-engine-cert)/fullchain.pem' '$(crt server.cert)'"
check "what follows certbot still runs: renewal is scheduled" "grep -q 'letsencrypt-renew.sh' '$LE_CRON_FILE'"

: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-cert.sh --domain Panel.Example.com --ip "$ADDRESS" --skip-dns-check
check "a name differing only in case is the same name" "! grep -q 'certbot/certbot' '$FAKE_DOCKER_LOG'"
run_as_root letsencrypt-request-cert.sh --domain other.example.com --ip "$ADDRESS" --skip-dns-check
check "another name is requested, with port 80 released for it and restored" \
    "before 'down sites-http' 'certbot/certbot certonly .*-d other.example.com' && next_after 'certbot/certbot certonly' 'up -d'"

: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check --force-renewal
check "--force-renewal asks certbot all the same" "grep -q -- 'certbot/certbot certonly .*--force-renewal' '$FAKE_DOCKER_LOG'"
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-ip-cert.sh "$ADDRESS" --force-renewal
check "for the IP lineage too" "grep -q -- 'certbot/certbot certonly .*--force-renewal' '$FAKE_DOCKER_LOG'"
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-cert.sh --ip "$ADDRESS" --skip-dns-check --dry-run
check "so does a dry run" "grep -q -- 'certbot/certbot certonly .*--dry-run' '$FAKE_DOCKER_LOG'"

# 61 days on: the 90-day domain lineage has 29 left (< 30), the IP one has expired.
: >"$FAKE_DOCKER_LOG"
days_on 61 letsencrypt-request-ip-cert.sh "$ADDRESS"
check "due: the IP lineage is requested with port 80 released, then restored" \
    "before 'down sites-http' 'certbot/certbot certonly .*--ip-address $ADDRESS' && next_after 'certbot/certbot certonly' 'up -d'"
: >"$FAKE_DOCKER_LOG"
days_on 61 letsencrypt-request-cert.sh --ip="$ADDRESS" --skip-dns-check
check "due: so is the domain lineage" \
    "before 'down sites-http' 'certbot/certbot certonly .*-d other.example.com' && next_after 'certbot/certbot certonly' 'up -d'"
check "and the reissued one is served" "same_file '$(live panelalpha-engine-cert)/fullchain.pem' '$(crt server.cert)'"

if [ "$failures" -gt 0 ]; then
    echo "${failures} failure(s); last output:"
    cat "$SANDBOX/out"
    exit 1
fi
echo "all passed"
