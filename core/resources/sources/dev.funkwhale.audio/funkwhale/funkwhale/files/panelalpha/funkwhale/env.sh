#!/bin/sh
# Sourced by the front nginx only (front.sh). Its config template substitutes
# FUNKWHALE_HOSTNAME and FUNKWHALE_PROTOCOL, which do not exist as container
# environment on their own -- the account's public name arrives as FUNKWHALE_URL
# (http://localhost, rewritten to the public https URL by ComposePlaceholders
# because the key ends in _URL). Split it into the two the template needs.
#
# The Django services do not need this: config/settings/common.py:174 reads
# FUNKWHALE_URL directly and derives both from it, which is why FUNKWHALE_URL is
# a plain container env there and works for `docker compose exec` too.
url="${FUNKWHALE_URL:-}"
case "${url}" in
    http://localhost|http://localhost/|'') url='https://localhost' ;;
esac
url="${url%/}"

scheme=$(printf '%s' "${url}" | sed -n 's#^\([a-zA-Z][a-zA-Z0-9+.-]*\)://.*#\1#p')
host=$(printf '%s' "${url}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/:].*##')

FUNKWHALE_HOSTNAME="${host:-localhost}"
FUNKWHALE_PROTOCOL="${scheme:-https}"
export FUNKWHALE_HOSTNAME FUNKWHALE_PROTOCOL

echo "[panelalpha] front FUNKWHALE_HOSTNAME=${FUNKWHALE_HOSTNAME} FUNKWHALE_PROTOCOL=${FUNKWHALE_PROTOCOL}" >&2
