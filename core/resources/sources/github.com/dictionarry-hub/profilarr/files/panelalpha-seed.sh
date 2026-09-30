#!/bin/bash
# Creates Profilarr's one password account from ~/.panelalpha/app-credentials.env,
# only when none exists. The app runs here on loopback in a container with no
# published port, so nobody else can reach /auth/setup meanwhile.
set -euo pipefail
: "${PROFILARR_ADMIN_USER:?missing}" "${PROFILARR_ADMIN_PASSWORD:?missing}"
URL=http://127.0.0.1:6868

HOST=127.0.0.1 PORT=6868 /entrypoint.sh &
app=$!
trap 'kill "$app" 2>/dev/null || true; wait "$app" 2>/dev/null || true' EXIT

for _ in $(seq 1 90); do
    curl -fsS -o /dev/null "$URL/api/v1/health" && break
    kill -0 "$app" 2>/dev/null || { echo "profilarr: app exited during seeding" >&2; exit 1; }
    sleep 1
done
curl -fsS -o /dev/null "$URL/api/v1/health"

# GET /auth/setup answers 200 while no password account exists, 303 once one does.
if [ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/auth/setup")" = 200 ]; then
    curl -s -o /dev/null -H "Origin: $URL" \
        --data-urlencode "username=$PROFILARR_ADMIN_USER" \
        --data-urlencode "password=$PROFILARR_ADMIN_PASSWORD" \
        --data-urlencode "confirmPassword=$PROFILARR_ADMIN_PASSWORD" \
        "$URL/auth/setup"
    echo "profilarr: created password account '$PROFILARR_ADMIN_USER'"
else
    echo "profilarr: password account present, nothing to seed"
fi

# Setup must now be closed; a successful login answers 303 with a session cookie.
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/auth/setup")" = 303 ] \
    || { echo "profilarr: /auth/setup is still open" >&2; exit 1; }
