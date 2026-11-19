#!/bin/bash
# Sets the engine's login on pyLoad's built-in user while it still has the
# default password `pyload`. Installed as a linuxserver custom init script, so it
# runs as root after /config/settings/pyload.cfg is in place and before pyLoad
# starts. A password changed in pyLoad is never touched.
DB=/config/data/pyload.db
if [ -z "${PYLOAD_ADMIN_PASSWORD:-}" ]; then
    echo "[panelalpha] pyload: no generated login in the environment; it stays pyload/pyload" >&2
    exit 0
fi
export LD_PRELOAD="/lib/libgcompat.so.0"

# pyLoad creates its database, with pyload/pyload, on its first start. A dry run
# is that start without serving: it creates the database and exits.
if [ ! -f "${DB}" ]; then
    s6-setuidgid abc pyload --userdir /config --storagedir /downloads --dry-run >/dev/null 2>&1 \
        || echo "[panelalpha] pyload: the dry run to create the database failed" >&2
fi

s6-setuidgid abc python3 - <<'PY' || echo "[panelalpha] pyload: could not set the login; it stays pyload/pyload" >&2
import os
import sqlite3
import sys

from pyload.core.database.user_database import _check_password, _gensalt, _salted_password

db = "/config/data/pyload.db"
if not os.path.isfile(db):
    sys.exit("no database at " + db)

name = os.environ.get("PYLOAD_ADMIN_USERNAME") or "admin"
con = sqlite3.connect(db)
row = con.execute("SELECT id, password FROM users WHERE name = 'pyload'").fetchone()
if row is None or not _check_password(row[1], "pyload"):
    sys.exit(0)  # no default login left: somebody chose theirs
if name != "pyload" and con.execute("SELECT 1 FROM users WHERE name = ?", (name,)).fetchone():
    sys.exit("a user " + name + " exists already")

# `password = ?` again: only the row still holding the default is changed.
con.execute(
    "UPDATE users SET name = ?, password = ? WHERE id = ? AND password = ?",
    (name, _salted_password(os.environ["PYLOAD_ADMIN_PASSWORD"], _gensalt()), row[0], row[1]),
)
con.commit()
print("[panelalpha] pyload: the login is the one the engine generated")
PY
exit 0
