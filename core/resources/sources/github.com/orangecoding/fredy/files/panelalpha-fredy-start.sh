#!/bin/sh
# Sets the engine's login on Fredy's admin while it still has the default password
# `admin`, then runs Fredy's own entrypoint (which hands /db and /conf to node).
if [ -n "${FREDY_ADMIN_PASSWORD:-}" ]; then
    (cd /fredy && node panelalpha-fredy-login.mjs) \
        || echo "[panelalpha] fredy: could not set the admin login; it stays admin/admin" >&2
fi
exec /usr/local/bin/docker-entrypoint.sh "$@"
