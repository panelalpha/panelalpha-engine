#!/bin/bash
#
# Obtain a Let's Encrypt certificate for the host's bare public IP address.
#
# Let's Encrypt issues IP certificates only under the short-lived profile
# (about six days), so this lineage is kept next to the served one rather than
# served itself -- see letsencrypt-lib.sh. It lands in crt/server-ip.cert +
# crt/server-ip.key for an operator who wants nginx to present it, and
# --install puts it on :2011 as crt/server.* (the pre-domain behaviour, and
# what the installer falls back to when no domain certificate can be issued).
#
# Usage: letsencrypt-request-ip-cert.sh [IP] [options]
#   --ip ADDR        the public address (positional works too)
#   --email ADDR     ACME account email; default: cert_email setting, else none
#   --install        serve this certificate on :2011 (crt/server.*)
#   --staging        Let's Encrypt staging
#   --force-renewal  reissue even when not due
#   --no-cron        do not schedule renewal

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# No cron run retries a first issuance the way it retries a renewal.
LE_CERTBOT_TIMEOUT="${LE_CERTBOT_TIMEOUT:-600}"
# shellcheck source=./letsencrypt-lib.sh
source "${SCRIPT_DIR}/letsencrypt-lib.sh"

IP_ADDRESS=""
EMAIL=""
INSTALL=0
STAGING=0
FORCE_RENEWAL=0
CRON=1

while [ $# -gt 0 ]; do
    case "$1" in
    --ip) IP_ADDRESS="$2"; shift 2 ;;
    --ip=*) IP_ADDRESS="${1#*=}"; shift ;;
    --email) EMAIL="$2"; shift 2 ;;
    --email=*) EMAIL="${1#*=}"; shift ;;
    --install) INSTALL=1; shift ;;
    --staging) STAGING=1; shift ;;
    --force-renewal) FORCE_RENEWAL=1; shift ;;
    --no-cron) CRON=0; shift ;;
    -h | --help) sed -n '/^# Usage/,/^$/{s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) le_error "Unknown option: $1" ;;
    *) IP_ADDRESS="$1"; shift ;;
    esac
done

[ "$(id -u)" = 0 ] || le_error "Run as root: certbot needs :80 and /etc/letsencrypt"

if [ -z "$IP_ADDRESS" ]; then
    IP_ADDRESS=$(le_public_ip)
fi
le_is_ipv4 "$IP_ADDRESS" || le_error "'${IP_ADDRESS}' is not an IPv4 address"
if le_ip_is_private "$IP_ADDRESS"; then
    le_error "${IP_ADDRESS} is a private address; Let's Encrypt cannot certify it"
fi
if [ -z "$EMAIL" ]; then
    EMAIL=$(le_setting_get cert_email)
fi

args=(certonly --standalone --non-interactive --agree-tos
    --cert-name "$LE_IP_CERT_NAME" --required-profile shortlived --ip-address "$IP_ADDRESS")
# shellcheck disable=SC2207
args+=($(le_account_args "$EMAIL"))
[ "$STAGING" = 1 ] && args+=(--staging)
[ "$FORCE_RENEWAL" = 1 ] && args+=(--force-renewal)

if [ "$FORCE_RENEWAL" = 0 ] && le_lineage_current "$LE_IP_CERT_NAME" "$IP_ADDRESS"; then
    le_info "The certificate for ${IP_ADDRESS} is not yet due for renewal; the webserver keeps running"
else
    le_info "Requesting a Let's Encrypt certificate for ${IP_ADDRESS}"
    le_certbot_standalone "${args[@]}"
    status=$?
    [ "$status" = 0 ] || le_error "certbot did not issue a certificate for ${IP_ADDRESS}"
fi

le_install_lineage "$LE_IP_CERT_NAME" server-ip || exit 1
if [ "$INSTALL" = 1 ]; then
    le_install_lineage "$LE_IP_CERT_NAME" server || exit 1
    # Everything that tells a client where the engine lives reads APP_URL --
    # mcp:token:create, mcp:check, GET /system/info. Serving this certificate
    # without moving it leaves every one of them naming something the served
    # certificate is not for.
    le_update_app_url "$IP_ADDRESS"
fi
if [ "$CRON" = 1 ]; then
    le_ensure_renewal_cron
fi
