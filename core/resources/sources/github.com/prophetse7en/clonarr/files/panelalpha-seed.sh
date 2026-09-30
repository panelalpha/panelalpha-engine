#!/bin/sh
# Creates Clonarr's admin from ~/.panelalpha/clonarr/admin.env through its own
# /setup, only when none exists, and requires login for every address. The app
# runs here in a container with no published port, so nobody else can reach it.
set -eu
: "${CLONARR_ADMIN_USER:?missing}" "${CLONARR_ADMIN_PASSWORD:?missing}"
URL=http://127.0.0.1:6060

/entrypoint.sh &
app=$!
trap 'kill "$app" 2>/dev/null || true; wait "$app" 2>/dev/null || true' EXIT

for _ in $(seq 1 60); do
    wget -q -O /dev/null "$URL/api/health" && break
    kill -0 "$app" 2>/dev/null || { echo "clonarr: app exited during seeding" >&2; exit 1; }
    sleep 1
done

configured() { wget -q -O - "$URL/api/auth/status" | grep -q '"configured":true'; }

# Double-submit CSRF: any GET sets clonarr_csrf; the POST echoes it back.
csrf() {
    wget -S -q -O /dev/null "$URL/api/auth/status" 2>&1 \
        | sed -n 's/.*[Ss]et-[Cc]ookie: clonarr_csrf=\([0-9a-f]*\).*/\1/p' | head -n1
}

if configured; then
    echo "clonarr: admin present, nothing to seed"
else
    t=$(csrf)
    wget -q -O /dev/null --header "Cookie: clonarr_csrf=$t" \
        --post-data "csrf_token=$t&username=$CLONARR_ADMIN_USER&password=$CLONARR_ADMIN_PASSWORD&password_confirm=$CLONARR_ADMIN_PASSWORD" \
        "$URL/setup" || true
    configured || { echo "clonarr: /setup did not create the admin" >&2; exit 1; }
    echo "clonarr: created admin '$CLONARR_ADMIN_USER'"

    # Default "disabled for local addresses" would skip login for private
    # addresses, which is where the engine's proxy connects from. Loopback
    # (this script) still bypasses; busybox wget cannot PUT, hence nc.
    t=$(csrf)
    body='{"authenticationRequired":"enabled"}'
    printf 'PUT /api/config HTTP/1.0\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nCookie: clonarr_csrf=%s\r\nX-CSRF-Token: %s\r\nContent-Length: %s\r\n\r\n%s' \
        "$t" "$t" "${#body}" "$body" | nc 127.0.0.1 6060 | head -n1
fi

wget -q -O - "$URL/api/auth/status" | grep -q '"authentication_required":"enabled"' \
    || { echo "clonarr: login is not required for every address" >&2; exit 1; }
