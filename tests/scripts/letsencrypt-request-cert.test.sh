#!/bin/bash
# Exercises the name decision in letsencrypt-request-cert.sh without a host:
# no docker, no certbot, no network. What it guards is the order of
# precedence -- argument, then the cert_domain setting, then the address-
# derived default -- and that a private or malformed address never turns into
# a certificate request, since a failed challenge counts against the
# Let's Encrypt failure limit.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../scripts" && pwd)"
source "$(dirname "${BASH_SOURCE[0]}")/test-network.sh"
ADDRESS=$(tn_host PUBLIC 7)
DETECTED=$(tn_host PUBLIC_2 9)

# Sourcing defines the functions; `main` is guarded against sourcing.
# shellcheck source=./letsencrypt-request-cert.sh
source "${SCRIPT_DIR}/letsencrypt-request-cert.sh"

# Stand-ins for the parts that need a host.
FAKE_SETTING=""
le_setting_get() { echo "$FAKE_SETTING"; }
le_public_ip() { echo "${FAKE_PUBLIC_IP:-}"; }
le_info() { :; }
# le_error exits the shell; in a subshell that is a non-zero status we can read.

failures=0

expect_domain() {
    local label="$1" expected="$2"
    shift 2
    local actual
    actual="$(
        DOMAIN="" IP_ADDRESS="" BASE_DOMAIN="$LE_BASE_DOMAIN"
        parse_args "$@" && resolve_domain && echo "$DOMAIN"
    )"
    if [ "$actual" = "$expected" ]; then
        echo "PASS: ${label}"
    else
        echo "FAIL: ${label}: expected '${expected}', got '${actual}'"
        failures=$((failures + 1))
    fi
}

expect_refusal() {
    local label="$1"
    shift
    if (
        DOMAIN="" IP_ADDRESS="" BASE_DOMAIN="$LE_BASE_DOMAIN"
        parse_args "$@" && resolve_domain
    ) >/dev/null 2>&1; then
        echo "FAIL: ${label}: expected a refusal"
        failures=$((failures + 1))
    else
        echo "PASS: ${label}"
    fi
}

# --- the derived default ------------------------------------------------------

expect_domain "public address becomes the dashed .direct name" \
    "${ADDRESS//./-}.panelalpha.direct" --ip "$ADDRESS"
expect_domain "--base-domain changes the zone" \
    "${ADDRESS//./-}.example.net" --ip "$ADDRESS" --base-domain example.net
FAKE_PUBLIC_IP="$DETECTED" \
    expect_domain "detected address is used when --ip is absent" \
    "${DETECTED//./-}.panelalpha.direct"

# --- precedence -----------------------------------------------------------------

expect_domain "positional argument wins" "panel.example.com" panel.example.com --ip "$ADDRESS"
expect_domain "--domain wins" "panel.example.com" --domain panel.example.com --ip "$ADDRESS"
expect_domain "--domain=value form" "panel.example.com" --domain=panel.example.com --ip "$ADDRESS"
FAKE_SETTING="admin.example.org" \
    expect_domain "cert_domain setting beats the default" "admin.example.org" --ip "$ADDRESS"
FAKE_SETTING="admin.example.org" \
    expect_domain "argument beats the cert_domain setting" "panel.example.com" panel.example.com --ip "$ADDRESS"

# --- refusals -------------------------------------------------------------------

expect_refusal "private address gets no default name" --ip "$TN_PRIVATE_10"
expect_refusal "link-local address gets no default name" --ip "$TN_METADATA"
expect_refusal "no address and no domain" --ip ""
expect_refusal "malformed address" --ip "${ADDRESS%.*}.999"
expect_refusal "an address is not a domain" --domain "$ADDRESS"
expect_refusal "a bare label is not a domain" --domain panel
expect_refusal "two positional domains" a.example.com b.example.com
expect_refusal "unknown option" --bogus

# --- helpers --------------------------------------------------------------------

if le_is_fqdn "${ADDRESS//./-}.panelalpha.direct" && ! le_is_fqdn "-bad.example.com" && ! le_is_fqdn "a..b"; then
    echo "PASS: le_is_fqdn"
else
    echo "FAIL: le_is_fqdn"
    failures=$((failures + 1))
fi

if [ "$(le_account_args "")" = "--register-unsafely-without-email" ] \
    && [ "$(le_account_args ops@example.com)" = "--email ops@example.com --no-eff-email" ]; then
    echo "PASS: le_account_args"
else
    echo "FAIL: le_account_args"
    failures=$((failures + 1))
fi

if [ "$failures" -gt 0 ]; then
    echo "${failures} failure(s)"
    exit 1
fi
echo "all passed"
