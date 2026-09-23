#!/bin/sh
# One-shot, run to completion before the app is allowed to serve. Migrates the
# schema and seeds the Django admin. `app` waits on this with
# service_completed_successfully, so a failed migration fails the deploy instead
# of publishing a broken site, and the admin exists before the first request.
set -e

say() { echo "[panelalpha/init] $*"; }
cd /opt/bitpoll

# manage.py sets this for its own subcommands, but the raw `python -c` probes
# below need it explicitly or django.setup() raises ImproperlyConfigured and
# the connection wait spins forever.
export DJANGO_SETTINGS_MODULE=bitpoll.settings

# compose already gates this on the db healthcheck; this is a second belt for
# the window where postgres answers pg_isready on its bootstrap socket while
# still restarting for the real one. Ask through Django so the driver and DSN
# are exactly what migrate will use.
i=0
until python -c "import django; django.setup(); from django.db import connections; connections['default'].ensure_connection()" 2>/dev/null
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

# Seed the owner admin only when it is missing, so a redeploy never resets a
# password the owner changed and never fails on "username already taken".
# DJANGO_SUPERUSER_{USERNAME,EMAIL,PASSWORD} come from ~/.panelalpha/bitpoll/.
if [ -n "${DJANGO_SUPERUSER_USERNAME:-}" ]; then
    exists="$(python -c "import django; django.setup(); from django.contrib.auth import get_user_model; import os; print(1 if get_user_model().objects.filter(username=os.environ['DJANGO_SUPERUSER_USERNAME']).exists() else 0)")"
    if [ "${exists}" = "1" ]; then
        say "admin '${DJANGO_SUPERUSER_USERNAME}' already exists, leaving it untouched"
    else
        say "creating admin '${DJANGO_SUPERUSER_USERNAME}'"
        python manage.py createsuperuser --noinput \
            --username "${DJANGO_SUPERUSER_USERNAME}" \
            --email "${DJANGO_SUPERUSER_EMAIL:-admin@example.com}"
    fi
fi

say "done"
