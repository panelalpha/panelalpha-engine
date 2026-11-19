#!/bin/sh
# Runs as root before Cherry's own entrypoint (/init, s6-overlay):
#   0. PORT back to the image's own 5173. The image's nginx listens on 8000
#      (what it EXPOSEs and the engine routes) and proxies to Node on $PORT;
#      the generated service sets PORT to the routed port, so Node and nginx
#      raced for 8000 and the loser crash-looped (a 502 when nginx won).
#   1. JWT_SECRET, without which no sign-in works: Cherry signs its session
#      token with it and has no default. Derived from the account's
#      PA_INSTANCE_SECRET (per account, stable across deploys and rebuilds),
#      unless the customer set one.
#   2. The first admin, from the login the engine generated, only while the
#      database has no user at all.
# The Dockerfile COPYs only its own directories, so this file is never in the
# image.
set -e

say() { echo "[panelalpha] cherry: $*" >&2; }

PORT=5173
export PORT

if [ -z "${JWT_SECRET:-}" ] && [ -n "${PA_INSTANCE_SECRET:-}" ]; then
    JWT_SECRET="$(printf 'cherry-jwt:%s' "${PA_INSTANCE_SECRET}" | sha256sum | cut -d' ' -f1)"
    export JWT_SECRET
fi

# s6's prepare step chowns /data too, but the CLI below runs first and has to
# create the database as the user Cherry runs as.
mkdir -p /data
chown nodejs:nodejs /data

# In a subshell: the admin's password reaches the CLI, not the server.
(
    if [ ! -r /pa/app-credentials.env ]; then
        say "no /pa/app-credentials.env; no admin created"
        exit 0
    fi
    set -a
    . /pa/app-credentials.env
    set +a
    if [ -z "${CHERRY_ADMIN_EMAIL:-}" ] || [ -z "${CHERRY_ADMIN_PASSWORD:-}" ]; then
        say "CHERRY_ADMIN_EMAIL or CHERRY_ADMIN_PASSWORD is empty; no admin created"
        exit 0
    fi
    cd /app
    # `list-users` opens the database, which creates and migrates it, and
    # prints "SUCCESS" then the users as { items: [ ... ] }.
    users="$(/command/s6-setuidgid nodejs node /app/cherry.cjs list-users)" || {
        say "cherry list-users failed; no admin created"
        exit 0
    }
    if ! printf '%s\n' "${users}" | grep -q 'items: \[\]'; then
        say "the database has users already; left alone"
        exit 0
    fi
    /command/s6-setuidgid nodejs node /app/cherry.cjs create-user \
        "${CHERRY_ADMIN_EMAIL}" "${CHERRY_ADMIN_PASSWORD}" --admin >/dev/null \
        && say "created the admin ${CHERRY_ADMIN_EMAIL}" \
        || say "cherry create-user failed; no admin created"
)

exec /init "$@"
