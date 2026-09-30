#!/bin/sh
# Creates autobrr's login user from ~/.panelalpha/app-credentials.env, only while
# onboarding is open. autobrr runs here on loopback with no published port.
set -eu
: "${AUTOBRR_ADMIN_USER:?missing}" "${AUTOBRR_ADMIN_PASSWORD:?missing}"
API=http://127.0.0.1:7474/api

/usr/local/bin/autobrr --config /config &
app=$!
trap 'kill "$app" 2>/dev/null || true; wait "$app" 2>/dev/null || true' EXIT

for _ in $(seq 1 90); do
    curl -fs -o /dev/null "$API/healthz/liveness" 2>/dev/null && break
    kill -0 "$app" 2>/dev/null || { echo "autobrr: app exited during seeding" >&2; exit 1; }
    sleep 1
done
curl -fsS -o /dev/null "$API/healthz/liveness"

# GET /auth/onboard answers 204 while no user exists, 503 once one does.
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$API/auth/onboard")" = 204 ]; then
    jq -n --arg u "$AUTOBRR_ADMIN_USER" --arg p "$AUTOBRR_ADMIN_PASSWORD" '{username:$u,password:$p}' \
      | curl -fsS -o /dev/null -H 'Content-Type: application/json' --data-binary @- "$API/auth/onboard"
    echo "autobrr: created user '$AUTOBRR_ADMIN_USER'"
else
    echo "autobrr: user present, nothing to seed"
fi

[ "$(curl -s -o /dev/null -w '%{http_code}' "$API/auth/onboard")" = 503 ] \
    || { echo "autobrr: onboarding is still open" >&2; exit 1; }
