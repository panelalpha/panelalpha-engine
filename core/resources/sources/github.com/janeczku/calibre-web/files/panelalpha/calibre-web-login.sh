#!/usr/bin/with-contenv bash
# LinuxServer custom init script (see start.sh): runs as root after the image's
# init-calibre-web-config has created /config/app.db and before Calibre-Web
# starts. A new app.db has Calibre-Web's built-in admin/admin123; while that
# admin still has the default password, give it the engine's username and
# password. A password changed in Calibre-Web is never touched.
[ -n "${CALIBRE_WEB_ADMIN_PASSWORD:-}" ] || exit 0
[ -f /config/app.db ] || exit 0
NAME="${CALIBRE_WEB_ADMIN_USER:-admin}"

# Prints the built-in admin's name while its password is still admin123; renames it to $NAME.
OLD=$(NAME="$NAME" python3 - <<'PY'
import os, sqlite3
from werkzeug.security import check_password_hash
db = sqlite3.connect('/config/app.db')
row = db.execute("SELECT id, name, password FROM user WHERE role & 1 ORDER BY id LIMIT 1").fetchone()
if row and row[2] and check_password_hash(row[2], 'admin123'):
    db.execute("UPDATE user SET name = ? WHERE id = ?", (os.environ['NAME'], row[0]))
    db.commit()
    print(row[1])
PY
) || { echo "[panelalpha] calibre-web: could not read app.db; the login stays admin/admin123" >&2; exit 0; }
[ -n "$OLD" ] || exit 0

# Calibre-Web's own password change (cps.py -s user:password), as the app user.
export CALIBRE_DBPATH=/config
cd /app/calibre-web && s6-setuidgid abc python3 /app/calibre-web/cps.py -s "${NAME}:${CALIBRE_WEB_ADMIN_PASSWORD}" >/dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "[panelalpha] calibre-web: the admin's login is the one the engine generated"
else
    echo "[panelalpha] calibre-web: could not set the admin password; it stays admin123" >&2
fi
exit 0
