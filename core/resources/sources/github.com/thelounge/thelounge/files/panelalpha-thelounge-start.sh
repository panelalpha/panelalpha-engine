#!/bin/sh
# Adds the engine's login as The Lounge's first user while it has no user at
# all, with upstream's own `thelounge add`, then starts the server as the
# repository's start script does (`node index start`).
set -e
cd /app
USERS="${THELOUNGE_HOME:?}/users"
if [ -n "${THELOUNGE_PASSWORD:-}" ] && ! ls "$USERS"/*.json >/dev/null 2>&1; then
    # `add` refuses to run before `start` has made the users directory once.
    mkdir -p "$USERS"
    chmod 700 "$USERS"
    node index add "${THELOUNGE_USERNAME:-admin}" --password "$THELOUNGE_PASSWORD" --save-logs \
        && echo "[panelalpha] thelounge: the first user is the login the engine generated" \
        || echo "[panelalpha] thelounge: could not add the first user" >&2
fi
exec node index start
