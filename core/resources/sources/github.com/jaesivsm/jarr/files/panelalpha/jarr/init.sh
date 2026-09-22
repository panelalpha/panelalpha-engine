#!/bin/sh
# One-shot, run to completion inside the jarr-server image before server/worker
# serve. Creates the schema and seeds the admin. server/worker wait on this with
# service_completed_successfully, so a failed init fails the deploy instead of
# publishing a broken site, and the admin exists before the first request.
set -e

say() { echo "[panelalpha/init] $*"; }
cd /jarr

# Wait for Postgres to accept a real connection through the exact driver + DSN
# that create_all will use (compose already gates on the db healthcheck; this
# covers the window where pg answers pg_isready on its bootstrap socket while
# still restarting for the real one).
i=0
until pipenv run python -c "from jarr.bootstrap import engine; engine.connect().close()" 2>/dev/null
do
    i=$((i + 1))
    if [ "${i}" -ge 90 ]; then
        say "database never accepted a connection; last error:"
        pipenv run python -c "from jarr.bootstrap import engine; engine.connect().close()" || true
        exit 1
    fi
    sleep 2
done

# create_all is idempotent (only builds missing tables); the admin is seeded only
# when absent, so a redeploy never resets a password the owner changed and never
# fails on a duplicate login. Credentials come from JARR_ADMIN_LOGIN/PASSWORD in
# the environment (from ~/.panelalpha/jarr/app.env via the init service env_file);
# they are not JARR config keys, so the_conf ignores them.
say "creating schema and seeding admin if missing"
pipenv run python - <<'PY'
import os
from jarr.bootstrap import Base, engine, sqlalchemy_registry
from jarr.controllers.user import UserController

sqlalchemy_registry.configure()
Base.metadata.create_all(engine)

login = os.environ["JARR_ADMIN_LOGIN"]
password = os.environ["JARR_ADMIN_PASSWORD"]
uc = UserController()
if uc.read(login=login).first():
    print("[panelalpha/init] admin '%s' already exists, leaving it" % login)
else:
    uc.create(is_admin=True, is_api=True, login=login, password=password)
    print("[panelalpha/init] created admin '%s'" % login)
PY

say "done"
