#!/bin/sh
# One-shot before the web service publishes its port: sign the admin up through
# the app's own endpoint while it listens on 127.0.0.1 only. No-op once a user exists.
set -e
cd /app
DB=/app/data/gitea-mirror.db
count_users() { sqlite3 "$DB" 'SELECT count(*) FROM users;' 2>/dev/null || echo 0; }

if [ -s "$DB" ] && [ "$(count_users)" -gt 0 ]; then
    echo "users exist; not seeding the admin"
    exit 0
fi

./docker-entrypoint.sh &
PID=$!
i=0
until wget -q -O /dev/null http://127.0.0.1:4321/api/health; do
    i=$((i + 1))
    if [ "$i" -gt 90 ] || ! kill -0 "$PID" 2>/dev/null; then
        echo "app did not come up for seeding" >&2
        exit 1
    fi
    sleep 2
done

wget -q -O /dev/null \
    --header 'Content-Type: application/json' \
    --header "Origin: ${BETTER_AUTH_URL}" \
    --post-data "{\"name\":\"${GM_ADMIN_NAME}\",\"email\":\"${GM_ADMIN_EMAIL}\",\"password\":\"${GM_ADMIN_PASSWORD}\"}" \
    http://127.0.0.1:4321/api/auth/sign-up/email || true

kill "$PID" 2>/dev/null || true
wait "$PID" 2>/dev/null || true

if [ "$(count_users)" -gt 0 ]; then
    echo "admin created"
else
    echo "admin sign-up failed" >&2
    exit 1
fi
