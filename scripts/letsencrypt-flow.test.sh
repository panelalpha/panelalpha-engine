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
#
# Needs bash, openssl and coreutils; no root, no network.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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
echo "APP_URL=https://203.0.113.7:2011" >"$LE_ENGINE_DIR/.env-core"
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

# docker run ... certbot/certbot ARGS
while [ $# -gt 0 ] && [ "$1" != "certbot/certbot" ]; do shift; done
shift
[ "${FAKE_CERTBOT_FAIL:-0}" = 1 ] && exit 1
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

run_as_root letsencrypt-request-cert.sh --ip 203.0.113.7 --skip-dns-check
check "request-cert exits 0" "[ $? = 0 ]"
check "lineage issued for the dashed name" \
    "grep -q -- '-d 203-0-113-7.panelalpha.direct' '$FAKE_DOCKER_LOG' && [ -f '$(live panelalpha-engine-cert)/fullchain.pem' ]"
check "account registered without an email when none is known" "grep -q -- '--register-unsafely-without-email' '$FAKE_DOCKER_LOG'"
check "served cert is the domain lineage" "same_file '$(live panelalpha-engine-cert)/fullchain.pem' '$(crt server.cert)'"
check "served key is a real file, mode 600" "[ ! -L '$(crt server.key)' ] && [ \"\$(stat -c %a '$(crt server.key)')\" = 600 ]"
check "cert_domain setting records the name" "[ \"\$(cat '$FAKE_SETTINGS_DIR/cert_domain')\" = 203-0-113-7.panelalpha.direct ]"
check "webserver stopped for the challenge and stack restored" \
    "grep -q 'down sites-http' '$FAKE_DOCKER_LOG' && grep -q 'up -d' '$FAKE_DOCKER_LOG'"
check "core's nginx reloaded so it loads the new pair" "grep -q 'exec -T core nginx -s reload' '$FAKE_DOCKER_LOG'"
check "core is never restarted" "! grep -qE 'restart( [a-z-]+)* core( |\$)' '$FAKE_DOCKER_LOG'"
check "queue workers told to reread APP_URL" "grep -q 'exec -T core php artisan queue:restart' '$FAKE_DOCKER_LOG'"
check "renewal scheduled" "grep -q 'letsencrypt-renew.sh' '$LE_CRON_FILE'"
check "APP_URL moved off the bare IP onto the served name" \
    "grep -qx 'APP_URL=https://203-0-113-7.panelalpha.direct:2011' '$LE_ENGINE_DIR/.env-core'"

# --- 2. the setting drives the next request ----------------------------------------------

echo "panel.example.com" >"$FAKE_SETTINGS_DIR/cert_domain"
echo "ops@example.com" >"$FAKE_SETTINGS_DIR/cert_email"
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-cert.sh --ip 203.0.113.7 --skip-dns-check
check "cert_domain setting is requested when no argument is given" "grep -q -- '-d panel.example.com' '$FAKE_DOCKER_LOG'"
check "cert_email setting registers the account" "grep -q -- '--email ops@example.com' '$FAKE_DOCKER_LOG'"
check "APP_URL follows a changed cert_domain" "grep -qx 'APP_URL=https://panel.example.com:2011' '$LE_ENGINE_DIR/.env-core'"

echo "APP_URL=https://ops.example.net:2011" >"$LE_ENGINE_DIR/.env-core"
run_as_root letsencrypt-request-cert.sh --domain another.example.com --ip 203.0.113.7 --skip-dns-check
check "an operator's own APP_URL is left alone" "grep -qx 'APP_URL=https://ops.example.net:2011' '$LE_ENGINE_DIR/.env-core'"

# --- 3. the IP lineage lands beside, not over -------------------------------------------------

served_before=$(md5sum <"$(crt server.cert)")
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-ip-cert.sh 203.0.113.7
check "request-ip-cert exits 0" "[ $? = 0 ]"
check "IP lineage requested under the short-lived profile" \
    "grep -q -- '--required-profile shortlived --ip-address 203.0.113.7' '$FAKE_DOCKER_LOG'"
check "IP cert installed as crt/server-ip.*" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server-ip.cert)'"
check "served pair untouched by the IP request" "[ \"\$(md5sum <'$(crt server.cert)')\" = '$served_before' ]"

: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-request-ip-cert.sh 203.0.113.7 --install
check "--install serves the IP cert" "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)'"

# --- 4. a failed request leaves the served pair alone ---------------------------------------------

served_before=$(md5sum <"$(crt server.cert)")
FAKE_CERTBOT_FAIL=1 run_as_root letsencrypt-request-cert.sh --ip 203.0.113.7 --skip-dns-check
check "certbot failure is reported" "[ $? != 0 ]"
check "served pair unchanged after a failed request" "[ \"\$(md5sum <'$(crt server.cert)')\" = '$served_before' ]"

# --- 5. renewal ---------------------------------------------------------------------------------

# Put the domain lineage back on :2011 first.
run_as_root letsencrypt-request-cert.sh --ip 203.0.113.7 --skip-dns-check
: >"$FAKE_DOCKER_LOG"
run_as_root letsencrypt-renew.sh
check "fresh lineages: renew does not stop the webserver" \
    "! grep -q 'renew' '$FAKE_DOCKER_LOG' && ! grep -q 'down sites-http' '$FAKE_DOCKER_LOG'"
check "fresh lineages: nothing reinstalled" "! grep -q 'nginx -s reload' '$FAKE_DOCKER_LOG'"

# A lineage inside its window: renewal runs and the served pair follows.
FAKE_CERT_DAYS=6 run_as_root letsencrypt-request-ip-cert.sh 203.0.113.7 --force-renewal
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
run_as_root letsencrypt-request-cert.sh --ip 203.0.113.7 --skip-dns-check
run_as_root letsencrypt-request-ip-cert.sh 203.0.113.7 --install
check "setup: the IP cert is served while a domain lineage exists" \
    "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)' && [ -f '$(live panelalpha-engine-cert)/fullchain.pem' ]"

: >"$FAKE_DOCKER_LOG"
served_before=$(md5sum <"$(crt server.cert)")
run_as_root letsencrypt-renew.sh --always
check "renewal keeps serving the IP lineage rather than restoring the domain one" \
    "same_file '$(live panelalpha-engine-ip-cert)/fullchain.pem' '$(crt server.cert)'"
check "the renewed IP cert did reach the served pair" \
    "[ \"\$(md5sum <'$(crt server.cert)')\" != '$served_before' ]"

if [ "$failures" -gt 0 ]; then
    echo "${failures} failure(s); last output:"
    cat "$SANDBOX/out"
    exit 1
fi
echo "all passed"
