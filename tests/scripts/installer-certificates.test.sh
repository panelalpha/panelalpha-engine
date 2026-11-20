#!/bin/bash
#
# installer.sh's request_certificates() serves the name the engine already has, else the IP certificate.
#
#   bash tests/scripts/installer-certificates.test.sh
#
# Needs bash and coreutils; no root, no docker, no network.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/test-network.sh"
ADDRESS=$(tn_host PUBLIC 7)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

sed -n "/^request_certificates() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >"$TMP/funcs.sh"
# shellcheck source=/dev/null
source "$TMP/funcs.sh"

PASS=0
FAIL=0
check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

echo_info() { printf 'info:%s\n' "$1"; }
echo_warning() { printf 'warning:%s\n' "$1"; }
echo_error() {
    printf 'error:%s\n' "$1"
    exit 101
}

mkdir -p "$TMP/settings"

# The composer stack, answering only the settings the function reads and writes.
docker() {
    printf 'docker %s\n' "$*" >>"$TMP/docker.log"
    case "$*" in
    *"artisan settings:get cert_domain"*) cat "$TMP/settings/cert_domain" 2>/dev/null ;;
    *"artisan settings:set cert_domain "*) printf '%s\n' "${!#}" >"$TMP/settings/cert_domain" ;;
    *"artisan settings:set cert_email "*) printf '%s\n' "${!#}" >"$TMP/settings/cert_email" ;;
    esac
}

ip() { printf 'dev eth0 proto kernel scope link src %s uid 0\n' "$ADDRESS"; }

ipcalc() { printf 'Address:\t%s\nNetmask:\t%s\n' "$1" "$TN_MASK_24"; }

curl() { printf '%s\n' "$ADDRESS"; }

# The Let's Encrypt scripts the function shells out to, logged rather than run.
bash() {
    printf 'bash %s\n' "$*" >>"$TMP/cert.log"
    case "$*" in
    *letsencrypt-request-cert.sh*)
        [ "${NAME_CERT_FAILS:-0}" = 0 ] || return 1
        [ -z "$DOMAIN" ] || printf '%s\n' "$DOMAIN" >"$TMP/settings/cert_domain"
        ;;
    *letsencrypt-request-ip-cert.sh*)
        [ "${IP_CERT_FAILS:-0}" = 0 ] || return 1
        ;;
    esac
    return 0
}

settings_set() { grep -qx "$1" "$TMP/docker.log" 2>/dev/null && echo yes || echo no; }

# A host at the start of one run: no state from the previous one.
reset() { # <recorded cert_domain, empty for none>
    rm -rf "$TMP/settings" "$TMP/docker.log" "$TMP/cert.log"
    mkdir -p "$TMP/settings"
    [ -z "$1" ] || printf '%s\n' "$1" >"$TMP/settings/cert_domain"
    DOMAIN=''
    CERT_EMAIL=''
    NO_CERT_REQUEST=0
    NO_LOCAL_IP=0
    ENGINE_CERT_DOMAIN=''
    ENGINE_CERT_IP=''
    IPV4=''
    CORE_URL="https://$TN_LOOPBACK:2011"
    API_URL=''
    MCP_URL=''
    NAME_CERT_FAILS=0
    IP_CERT_FAILS=0
}

calls() { sed 's#.*/##' "$TMP/cert.log" 2>/dev/null | tr '\n' ' ' | sed 's/ $//'; }

# An update passes no --domain: the recorded name is served, the IP lineage kept unserved.
reset "panel.example.com"
request_certificates >"$TMP/out"

check "an update serves the recorded name" "panel.example.com" "$ENGINE_CERT_DOMAIN"
check "the IP certificate is not installed over it" "" "$ENGINE_CERT_IP"
check "the URLs follow the name" "https://panel.example.com:2011" "$CORE_URL"
check "the API URL follows the name" "https://panel.example.com:2011/api" "$API_URL"
check "the name certificate is requested" \
    "letsencrypt-request-ip-cert.sh ${ADDRESS} letsencrypt-request-cert.sh --ip=${ADDRESS}" \
    "$(calls)"
check "the setting is left as it was" "no" \
    "$(settings_set "docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set cert_domain panel.example.com")"

# A host with no name is served on the IP certificate, which is installed.
reset ''
request_certificates >"$TMP/out"

check "a host with no name is served on the IP" "$ADDRESS" "$ENGINE_CERT_IP"
check "and no name is claimed" "" "$ENGINE_CERT_DOMAIN"
check "the URLs follow the address" "https://${ADDRESS}:2011" "$CORE_URL"
check "the IP certificate is the served one" \
    "letsencrypt-request-ip-cert.sh ${ADDRESS} --install" \
    "$(calls)"

# --domain still wins: it is recorded first, then served.
reset ''
DOMAIN='new.example.com'
request_certificates >"$TMP/out"

check "--domain is recorded" "yes" \
    "$(settings_set "docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan settings:set cert_domain new.example.com")"
check "--domain is what is served" "new.example.com" "$ENGINE_CERT_DOMAIN"
check "--domain is what is requested" \
    "letsencrypt-request-ip-cert.sh ${ADDRESS} letsencrypt-request-cert.sh --ip=${ADDRESS}" \
    "$(calls)"

# --no-cert-request obtains nothing, and says which name to point at the host.
reset "panel.example.com"
NO_CERT_REQUEST=1
request_certificates >"$TMP/out"

check "--no-cert-request obtains nothing" "" "$(calls)"
check "--no-cert-request still names the host" "yes" \
    "$(grep -qx 'info:Not requesting a certificate (--no-cert-request). Point panel.example.com at this host, then:' "$TMP/out" && echo yes || echo no)"

# A failed name request installs no IP certificate over the one being served.
reset "panel.example.com"
NAME_CERT_FAILS=1
request_certificates >"$TMP/out"

check "a failed name request keeps the self-signed certificate" "" "$ENGINE_CERT_IP$ENGINE_CERT_DOMAIN"
check "and says so" "yes" \
    "$(grep -qx 'warning:Could not obtain a Let'"'"'s Encrypt certificate for panel.example.com' "$TMP/out" && echo yes || echo no)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
