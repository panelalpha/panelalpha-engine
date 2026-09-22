#!/bin/sh
# One-shot, run to completion before the app container is allowed to start.
# The image's default CMD launches granian and never migrates, so on a first
# boot the site would answer 500 against an empty schema. `app` waits on this
# with service_completed_successfully, so a migration that fails stops the
# deploy instead of publishing a broken site.
#
# No user is created here: Passit is end-to-end encrypted and derives each
# user's keys from the client-side-hashed password at registration
# (passit_sdk/sdk.py), so the only account that can actually decrypt a vault is
# one the browser app registered. See credentials.txt.
set -e

say() { echo "[panelalpha/init] $*"; }
cd /code

# compose gates this on the db healthcheck already; this is the second belt for
# the case where postgres answers pg_isready on its bootstrap socket while
# still restarting for the real one. Ask through Django so the driver and DSN
# are exactly what migrate will use.
i=0
until python -c "import os,django; os.environ.setdefault('DJANGO_SETTINGS_MODULE','passit.settings'); django.setup(); from django.db import connections; connections['default'].ensure_connection()" 2>/dev/null
do
    i=$((i + 1))
    if [ "${i}" -ge 60 ]; then
        say "database never accepted a connection"
        exit 1
    fi
    sleep 2
done

say "migrating"
python manage.py migrate --noinput
say "done"
