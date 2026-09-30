#!/bin/bash
# Sets Houndarr's one admin account from ~/.panelalpha/houndarr/admin.env, only
# when none exists. The app listens on loopback in a container with no
# published port, so nobody else can reach /setup meanwhile.
set -euo pipefail
: "${HOUNDARR_ADMIN_USER:?missing}" "${HOUNDARR_ADMIN_PASSWORD:?missing}"
URL=http://127.0.0.1:8877

HOUNDARR_HOST=127.0.0.1 /entrypoint.sh python -m houndarr --data-dir /data &
app=$!
trap 'kill "$app" 2>/dev/null || true; wait "$app" 2>/dev/null || true' EXIT

for _ in $(seq 1 90); do
    curl -fsS -o /dev/null "$URL/api/health" && break
    kill -0 "$app" 2>/dev/null || { echo "houndarr: app exited during seeding" >&2; exit 1; }
    sleep 1
done
curl -fsS -o /dev/null "$URL/api/health"

# GET /setup answers 200 while no password is set, 302 to /login once one is.
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/setup")" = 200 ]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' \
        --data-urlencode "username=$HOUNDARR_ADMIN_USER" \
        --data-urlencode "password=$HOUNDARR_ADMIN_PASSWORD" \
        --data-urlencode "password_confirm=$HOUNDARR_ADMIN_PASSWORD" \
        "$URL/setup")
    echo "houndarr: /setup answered $code for '$HOUNDARR_ADMIN_USER'"
else
    echo "houndarr: admin present, nothing to seed"
fi

[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/setup")" = 302 ] \
    || { echo "houndarr: /setup is still open" >&2; exit 1; }
