#!/bin/sh
# Runs as the image's CMD, after its entrypoint.sh: scripts/init.py has
# migrated the database and, on a new one, created RSSBox's admin `admin`
# with the password `rssbox`. Sets the engine's login on it while it still has
# that password, then runs the server.
if [ -n "${RSSBOX_ADMIN_PASSWORD:-}" ]; then
    (cd /app && /opt/venv/bin/python manage.py shell -c "exec(open('/panelalpha/set-admin-login.py').read())") \
        || echo "[panelalpha] rssbox: could not set the admin login; it stays admin/rssbox" >&2
fi
exec "$@"
