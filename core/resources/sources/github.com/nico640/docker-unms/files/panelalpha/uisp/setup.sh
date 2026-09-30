#!/bin/sh
# Close UISP's unauthenticated first-run setup with the engine's admin login.
# Runs before the public `app` starts; a configured instance is left alone.
set -eu

say() { echo "[panelalpha/setup] $*"; }
BASE=https://uisp
: "${UISP_ADMIN_PASSWORD:?UISP_ADMIN_PASSWORD is not set}"
HOST="${PA_PUBLIC_HOST:?PA_PUBLIC_HOST is not set}"
USER="${UISP_ADMIN_USER:-admin}"
EMAIL="${UISP_ADMIN_EMAIL:-admin@${HOST}}"
COUNTRY="${UISP_COUNTRY:-US}"

status=""
for _ in $(seq 1 60); do
    status="$(curl -sk "${BASE}/nms/api/v2.1/nms/setup" || true)"
    case "${status}" in *isConfigured*) break ;; esac
    sleep 5
done
case "${status}" in
    *'"isConfigured":true'*) say "UISP is already set up"; exit 0 ;;
    *'"isConfigured":false'*) ;;
    *) say "setup status unavailable: ${status}"; exit 1 ;;
esac

# EULA is left for the admin to accept in the UI; no telemetry opt-in.
body=$(jq -n --arg h "${HOST}" --arg u "${USER}" --arg e "${EMAIL}" \
    --arg p "${UISP_ADMIN_PASSWORD}" --arg c "${COUNTRY}" \
    '{hostname:$h, useLetsEncrypt:false, eulaConfirmed:false,
      allowLoggingToSentry:false, allowLoggingToLogentries:false,
      smtp:{type:"nosmtp"},
      user:{username:$u, email:$e, password:$p, timezone:"UTC", country:$c, alerts:false}}')
mkdir -p /config/panelalpha
umask 077
code="$(curl -sk -o /config/panelalpha/setup.json -w '%{http_code}' \
    -H 'Content-Type: application/json' -X POST -d "${body}" \
    "${BASE}/nms/api/v2.1/nms/setup")"
if [ "${code}" != "200" ]; then
    say "POST /nms/api/v2.1/nms/setup answered ${code}: $(head -c 500 /config/panelalpha/setup.json)"
    exit 1
fi
say "admin ${USER} created; vault passphrase kept in /config/panelalpha/setup.json"
