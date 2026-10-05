#!/bin/bash
# One-shot database initialiser, run as root before the app service starts
# (app depends_on init: service_completed_successfully). Idempotent: it seeds
# only a fresh database and is a no-op on every redeploy.
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"; host="${host%%/*}"
[ -n "${host}" ] || host="localhost"
export SERVER_NAME="${host}"
export FLASK_APP=pyfedi.py
cd /app

echo "[panelalpha] pyfedi init: SERVER_NAME=${SERVER_NAME}"

# The media volume is created root-owned; the app runs as the image's `python`
# user and must be able to write uploads there. chown once, here, as root.
chown -R python:python /app/app/static/media 2>/dev/null || true

# init-db checks for the alembic_version table and refuses without it, so lay
# the schema down first. `flask db upgrade` is a no-op once at head, so this is
# safe to run on every deploy.
flask db upgrade

# flask init-db does db.drop_all()+db.create_all() and seeds Site/roles/
# languages/admin. drop_all() only touches SQLAlchemy model tables, so
# alembic_version (created by db upgrade, not a model) survives and the app's
# own `flask db upgrade` stays a no-op afterwards. Running it a SECOND time
# would wipe every row, so gate on the Site singleton: a fresh volume has the
# table (from db upgrade) but no row; an initialised one has the row.
STATE="$(python3 - <<'PY'
import os
from sqlalchemy import create_engine, inspect, text
engine = create_engine(os.environ["DATABASE_URL"])
seeded = False
insp = inspect(engine)
if insp.has_table("site"):
    with engine.connect() as c:
        seeded = c.execute(text("SELECT 1 FROM site LIMIT 1")).first() is not None
print("SKIP" if seeded else "SEED")
PY
)"

if [ "${STATE}" = "SEED" ]; then
    admin_user="${ADMIN_USER:-paadmin}"
    admin_email="${ADMIN_EMAIL:-admin@${SERVER_NAME}}"
    : "${ADMIN_PASSWORD:?ADMIN_PASSWORD must be in ~/.panelalpha/app-credentials.env}"
    echo "[panelalpha] pyfedi init: fresh database -- seeding site + admin '${admin_user}'"
    # init-db prompts for admin user name, email, password (it loops until the
    # name has no '@'/space and the password is >= 8 chars, both of which the
    # engine's generated login satisfies). The seeded admin is verified=True, so
    # login works immediately with no email confirmation. The password prompt is
    # pwinput, which needs a terminal (termios.error on a pipe), so upstream's
    # init-db runs with pwinput swapped for input().
    printf '%s\n%s\n%s\n' "${admin_user}" "${admin_email}" "${ADMIN_PASSWORD}" | python3 -c '
import sys, pwinput
pwinput.pwinput = lambda prompt="", mask="*": input(prompt)
from flask.cli import main
sys.argv = ["flask", "init-db"]
main()
'
    echo "[panelalpha] pyfedi init: seed complete"
else
    echo "[panelalpha] pyfedi init: site already initialised -- skipping init-db"
fi
