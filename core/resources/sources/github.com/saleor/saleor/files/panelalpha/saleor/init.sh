#!/bin/sh
# One-shot, run to completion before anything is allowed to listen.
#
# Four jobs, and the order they run in is the point.
#
#   1. Migrate. Saleor's CMD is uvicorn and nothing in the image migrates, so
#      without this the API answers every query with a ProgrammingError about a
#      missing relation.
#   2. Publish the static files the image built. The Dockerfile runs
#      collectstatic into /app/static at build time, but with DEBUG off
#      saleor/urls.py:55 stops routing /static/ entirely, so nginx serves that
#      directory and has to be given a copy of it.
#   3. Create this account's superuser.
#   4. Prove no other account can log in.
#
# Exiting non-zero fails the deploy: `api` depends on this with
# service_completed_successfully, so a database that never came up or a
# superuser that could not be created stops the stack instead of publishing an
# API nobody owns.
set -e

say() { echo "[panelalpha/init] $*"; }

# shellcheck disable=SC1091
. /app/panelalpha/saleor/env.sh

cd /app

# compose already gates this on the db healthcheck; this is the second belt,
# for the case where postgres passes pg_isready on its bootstrap socket and is
# still restarting for the real one.
attempt=0
until python3 -c "
import os, sys, psycopg
try:
    psycopg.connect(os.environ['DATABASE_URL'], connect_timeout=3).close()
except Exception:
    sys.exit(1)
" 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 60 ]; then
        say "database ${POSTGRES_HOST}:${POSTGRES_PORT:-5432} never accepted a connection"
        exit 1
    fi
    sleep 2
done

say "migrating"
python3 manage.py migrate --noinput

# /app/static is baked into the image by the Dockerfile's collectstatic; the
# volume is what nginx reads. Copied rather than mounted over, because mounting
# a volume at /app/static would hide the image's copy on the first boot and
# leave nginx with an empty directory.
if [ -d /app/static ]; then
    say "publishing static files"
    cp -a /app/static/. /srv/static/
fi
# MEDIA_ROOT is /app/media (settings.py:218) and is a volume, so it survives a
# rebuild. nginx reads the same volume.
mkdir -p /app/media

# ---------------------------------------------------------------------------
# The superuser.
#
# Saleor ships no default account -- the only place in the tree that creates one
# is saleor/core/management/commands/populatedb.py:86, which hardcodes
# admin@example.com and is a sample-data command this recipe never runs. So a
# migrated database has an empty auth table and nobody can do anything until
# somebody is created; there is no anonymous setup form to race, unlike some
# Django apps, because Saleor has no HTML admin at all.
#
# --no-imports: `manage.py shell -c` prepends "N objects imported
# automatically" to its output, and anything an app logs at registry load lands
# on stdout too, so the last line is the only one this can trust.
count_staff() {
    python3 manage.py shell --no-imports -c \
        'from saleor.account.models import User; print(User.objects.filter(is_superuser=True).count())' \
        2>/dev/null | tail -n 1 | tr -dc '0-9'
}

supers="$(count_staff)"
say "existing superusers: ${supers:-unreadable}"
if [ -z "${supers}" ]; then
    say "could not read the user count; refusing to guess"
    exit 1
fi

if [ "${supers}" = "0" ]; then
    if [ -z "${SALEOR_ADMIN_EMAIL:-}" ] || [ -z "${SALEOR_ADMIN_PASSWORD:-}" ]; then
        say "no administrator credentials in the environment; refusing to deploy an API with no owner"
        exit 1
    fi
    say "creating superuser ${SALEOR_ADMIN_EMAIL}"
    DJANGO_SUPERUSER_PASSWORD="${SALEOR_ADMIN_PASSWORD}" \
        python3 manage.py createsuperuser --noinput \
        --email "${SALEOR_ADMIN_EMAIL}"

    # createsuperuser can exit 0 without creating anything. Confirm: an API
    # with no owner is an API whose first caller becomes its owner.
    supers="$(count_staff)"
    if [ "${supers:-0}" = "0" ]; then
        say "the superuser was not created; stopping rather than publishing an unowned API"
        exit 1
    fi
else
    # Never touched again. The password in ~/.panelalpha/saleor/ is the one this
    # user was created with; if it was changed through the API, that is the
    # owner's business and overwriting it here would be taking their shop off
    # them.
    say "a superuser already exists; leaving it alone"
fi

# ---------------------------------------------------------------------------
# engine#200: an application that ships a default credential must not reach the
# public port still holding it. Saleor's is populatedb's admin@example.com. It
# is only ever created by that command, but "only ever" is a reading of the
# source and this is a measurement -- and a database restored from an upstream
# demo dump would carry it.
leftover="$(python3 manage.py shell --no-imports -c \
    'from saleor.account.models import User; print(User.objects.filter(email="admin@example.com").exclude(email__iexact="'"${SALEOR_ADMIN_EMAIL}"'").count())' \
    2>/dev/null | tail -n 1 | tr -dc '0-9')"
if [ -n "${leftover}" ] && [ "${leftover}" != "0" ]; then
    say "found ${leftover} account(s) on the upstream sample credential admin@example.com; deactivating"
    python3 manage.py shell --no-imports -c \
        'from saleor.account.models import User; User.objects.filter(email="admin@example.com").update(is_active=False, is_staff=False, is_superuser=False)'
fi

say "done"
