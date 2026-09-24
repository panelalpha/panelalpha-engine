#!/bin/sh
# One-shot `init` service. `ghost` depends on it having exited 0, so the owner
# is set up before anything publishes a port. An already set-up site costs one
# SELECT; otherwise Ghost boots here on loopback (running its migrations), the
# setup goes through its own API, and it is stopped again.
set -e
cd /var/lib/ghost

if node /pa/claim.js check; then
    echo '[panelalpha] ghost: owner already set up'
    exit 0
fi

# The image's own entrypoint: fixes content ownership, then runs as `node`.
server__host=127.0.0.1 docker-entrypoint.sh node current/index.js &
ghost_pid=$!

rc=0
node /pa/claim.js setup || rc=$?

kill "$ghost_pid" 2>/dev/null || true
wait "$ghost_pid" 2>/dev/null || true
exit "$rc"
