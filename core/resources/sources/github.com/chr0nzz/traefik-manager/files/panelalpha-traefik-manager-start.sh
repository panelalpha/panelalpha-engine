#!/bin/sh
# Runs as the image's command, after its own entrypoint (which drops to PUID/PGID
# when they are set). While manager.yml holds no password yet, the engine's
# password is set with Traefik Manager's own `flask reset-password --stdin`, so
# no temporary password is left for the log; a password set or changed in the app
# is never touched. Then runs the image's own command.
set -e
cd /app
if [ -n "${TM_ADMIN_PASSWORD:-}" ] && [ -z "${ADMIN_PASSWORD:-}" ] \
    && ! python3 -c 'import sys; from core.settings import load_settings; sys.exit(0 if load_settings().get("password_hash") else 1)'; then
    if printf '%s' "$TM_ADMIN_PASSWORD" | flask reset-password --stdin >/dev/null; then
        echo "[panelalpha] traefik-manager: the login is the password the engine generated"
    else
        echo "[panelalpha] traefik-manager: could not set the password; see the temporary one above" >&2
    fi
fi
unset TM_ADMIN_PASSWORD
exec "$@"
