#!/bin/bash
# One-shot that runs before the web service publishes its port. Calibre-Web
# creates admin/admin123 with a new app.db; this replaces that password with the
# per-account secret through Calibre-Web's own CLI (`cps.py -s user:pass`).
set -e
: "${CWA_ADMIN_PASSWORD:?CWA_ADMIN_PASSWORD is not set; prepare.sh did not run}"
export CALIBRE_DBPATH=/config
cd /app/calibre-web-automated

# Only while admin still has the shipped default: a password the owner changed
# later in the app is left alone on a redeploy.
if [ -f /config/app.db ] && ! python3 - <<'EOF'
import sqlite3
from werkzeug.security import check_password_hash
row = sqlite3.connect("/config/app.db").execute(
    "select password from user where lower(name) = 'admin'").fetchone()
raise SystemExit(0 if row and check_password_hash(row[0], "admin123") else 1)
EOF
then
    echo "[panelalpha] admin no longer has the default password; nothing to do"
    exit 0
fi

# On an empty volume this also creates app.db (init_db), as the image's first run does.
python3 cps.py -s "admin:${CWA_ADMIN_PASSWORD}"
