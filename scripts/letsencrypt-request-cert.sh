#!/bin/bash
#
# Obtain the engine's served certificate: a Let's Encrypt certificate for a
# DNS name, installed as crt/server.cert + crt/server.key for :2011.
#
# Which name, in order of precedence:
#   1. the argument or --domain
#   2. the `cert_domain` setting in the core database (the administrator's
#      domain, set with `pae-artisan settings:set cert_domain panel.example.com`)
#   3. the default derived from the public address: 203.0.113.7 becomes
#      203-0-113-7.panelalpha.direct, which the wildcard zone resolves back
#      to 203.0.113.7 -- no DNS to set up.
#
# The name is checked against the public address before certbot is called,
# because a failed HTTP-01 challenge still counts against Let's Encrypt's
# failure limit (5 per account per hostname per hour).
#
# Usage: letsencrypt-request-cert.sh [DOMAIN] [options]
#   --domain FQDN         the name to certify (see above)
#   --ip ADDR             the public address, when detection gets it wrong
#   --base-domain NAME    zone for the derived default (default panelalpha.direct)
#   --email ADDR          ACME account email; falls back to the `cert_email`
#                         setting, then to registering without one
#   --staging             Let's Encrypt staging: untrusted, but rate-limit free
#   --force-renewal       reissue even when the current one is not due
#   --skip-dns-check      do not verify the name resolves to this host
#   --no-install          obtain only; leave crt/server.* alone
#   --no-cron             do not schedule renewal
#   --dry-run             certbot --dry-run: full challenge, no certificate

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# No cron run retries a first issuance the way it retries a renewal.
LE_CERTBOT_TIMEOUT="${LE_CERTBOT_TIMEOUT:-600}"
# shellcheck source=./letsencrypt-lib.sh
source "${SCRIPT_DIR}/letsencrypt-lib.sh"

DOMAIN=""
IP_ADDRESS=""
BASE_DOMAIN="$LE_BASE_DOMAIN"
EMAIL=""
STAGING=0
FORCE_RENEWAL=0
SKIP_DNS_CHECK=0
INSTALL=1
CRON=1
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage: letsencrypt-request-cert.sh [DOMAIN] [options]
  --domain FQDN         the name to certify; default: the cert_domain setting,
                        else <dashed-public-ip>.panelalpha.direct
  --ip ADDR             the public address, when detection gets it wrong
  --base-domain NAME    zone for the derived default (default panelalpha.direct)
  --email ADDR          ACME account email; default: the cert_email setting,
                        else register without one
  --staging             Let's Encrypt staging: untrusted, but rate-limit free
  --force-renewal       reissue even when the current one is not due
  --skip-dns-check      do not verify the name resolves to this host
  --no-install          obtain only; leave crt/server.* alone
  --no-cron             do not schedule renewal
  --dry-run             certbot --dry-run: full challenge, no certificate
USAGE
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --domain=*) DOMAIN="${1#*=}"; shift ;;
        --ip) IP_ADDRESS="$2"; shift 2 ;;
        --ip=*) IP_ADDRESS="${1#*=}"; shift ;;
        --base-domain) BASE_DOMAIN="$2"; shift 2 ;;
        --base-domain=*) BASE_DOMAIN="${1#*=}"; shift ;;
        --email) EMAIL="$2"; shift 2 ;;
        --email=*) EMAIL="${1#*=}"; shift ;;
        --staging) STAGING=1; shift ;;
        --force-renewal) FORCE_RENEWAL=1; shift ;;
        --skip-dns-check) SKIP_DNS_CHECK=1; shift ;;
        --no-install) INSTALL=0; shift ;;
        --no-cron) CRON=0; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h | --help) usage; exit 0 ;;
        -*) le_error "Unknown option: $1" ;;
        *)
            [ -z "$DOMAIN" ] || le_error "Only one domain can be requested: got '$DOMAIN' and '$1'"
            DOMAIN="$1"
            shift
            ;;
        esac
    done
}

# Fills DOMAIN and IP_ADDRESS from the arguments, the setting and the host.
# Separated from the request so the decision can be tested without a host.
resolve_domain() {
    if [ -z "$IP_ADDRESS" ]; then
        IP_ADDRESS=$(le_public_ip)
    fi

    local source="argument"
    if [ -z "$DOMAIN" ]; then
        DOMAIN=$(le_setting_get cert_domain)
        source="cert_domain setting"
    fi
    if [ -z "$DOMAIN" ]; then
        [ -n "$IP_ADDRESS" ] || le_error "Could not determine the public address; pass --ip or --domain"
        le_is_ipv4 "$IP_ADDRESS" || le_error "'$IP_ADDRESS' is not an IPv4 address"
        if le_ip_is_private "$IP_ADDRESS"; then
            le_error "${IP_ADDRESS} is a private address: Let's Encrypt cannot reach it, and a default name would only resolve to it. Pass --domain for a name that reaches this host"
        fi
        DOMAIN=$(le_default_domain "$IP_ADDRESS" "$BASE_DOMAIN")
        source="default for ${IP_ADDRESS}"
    fi

    le_is_fqdn "$DOMAIN" || le_error "'$DOMAIN' is not a hostname a certificate can be issued for"
    le_info "Certificate domain: ${DOMAIN} (${source})"
}

check_dns() {
    [ "$SKIP_DNS_CHECK" = 0 ] || return 0
    [ -n "$IP_ADDRESS" ] || {
        le_warn "Public address unknown; skipping the DNS check"
        return 0
    }
    local resolved
    resolved=$(le_resolve "$DOMAIN")
    if [ -z "$resolved" ]; then
        le_error "${DOMAIN} does not resolve. Point an A record at ${IP_ADDRESS} first, or pass --skip-dns-check"
    fi
    if ! grep -qxF "$IP_ADDRESS" <<<"$resolved"; then
        le_error "${DOMAIN} resolves to $(tr '\n' ' ' <<<"$resolved")but this host is ${IP_ADDRESS}. Fix the A record, pass --ip if the detected address is wrong, or --skip-dns-check"
    fi
}

request() {
    if [ "$FORCE_RENEWAL" = 0 ] && [ "$DRY_RUN" = 0 ] && le_lineage_current "$LE_CERT_NAME" "$DOMAIN"; then
        le_info "The certificate for ${DOMAIN} is not yet due for renewal; the webserver keeps running"
        return 0
    fi
    local -a args
    args=(certonly --standalone --non-interactive --agree-tos
        --cert-name "$LE_CERT_NAME" -d "$DOMAIN")
    # shellcheck disable=SC2207
    args+=($(le_account_args "$EMAIL"))
    [ "$STAGING" = 1 ] && args+=(--staging)
    [ "$FORCE_RENEWAL" = 1 ] && args+=(--force-renewal)
    [ "$DRY_RUN" = 1 ] && args+=(--dry-run)

    le_info "Requesting a Let's Encrypt certificate for ${DOMAIN}"
    le_certbot_standalone "${args[@]}"
}

main() {
    parse_args "$@"
    [ "$(id -u)" = 0 ] || le_error "Run as root: certbot needs :80 and /etc/letsencrypt"

    if [ -z "$EMAIL" ]; then
        EMAIL=$(le_setting_get cert_email)
    fi
    # What APP_URL may still point at, if a different name was issued before.
    local previous_domain
    previous_domain=$(le_setting_get cert_domain)

    resolve_domain
    check_dns

    if ! request; then
        le_error "certbot did not issue a certificate for ${DOMAIN}; the served certificate is unchanged"
    fi
    [ "$DRY_RUN" = 0 ] || {
        le_info "Dry run passed for ${DOMAIN}"
        return 0
    }

    # Remember what was issued, so renewal, the updater and `pae-artisan
    # ssl:engine-cert:request` all keep working on the same name.
    le_setting_set cert_domain "$DOMAIN" || le_warn "Core is not running; the cert_domain setting was not recorded"
    if [ -n "$EMAIL" ]; then
        le_setting_set cert_email "$EMAIL" || true
    fi

    if [ "$INSTALL" = 1 ]; then
        le_install_lineage "$LE_CERT_NAME" server || le_error "The certificate was issued but could not be installed"
        le_update_app_url "$DOMAIN" "$previous_domain"
    fi
    if [ "$CRON" = 1 ]; then
        le_ensure_renewal_cron
    fi
    le_info "Done: https://${DOMAIN}:2011"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    main "$@"
fi
