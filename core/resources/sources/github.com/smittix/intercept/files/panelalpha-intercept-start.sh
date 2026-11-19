#!/bin/sh
# INTERCEPT creates its first admin from INTERCEPT_ADMIN_PASSWORD, and also sets
# that password on the admin on every start. Passed only while the database has
# no user, so a password changed in INTERCEPT is never overwritten. Then runs
# the image's own command.
set -e
if [ -n "${INTERCEPT_ADMIN_PASSWORD:-}" ] && python3 - <<'PY'
import os, sqlite3, sys
db = '/app/instance/intercept.db'
try:
    users = os.path.exists(db) and sqlite3.connect(db).execute('SELECT COUNT(*) FROM users').fetchone()[0]
except sqlite3.Error:
    users = 0
sys.exit(0 if users else 1)
PY
then
    unset INTERCEPT_ADMIN_PASSWORD
    echo "[panelalpha] intercept: the admin exists, its password is left as it is"
else
    echo "[panelalpha] intercept: a new database, the admin gets the login the engine generated"
fi
cd /app
exec /bin/bash start.sh "$@"
