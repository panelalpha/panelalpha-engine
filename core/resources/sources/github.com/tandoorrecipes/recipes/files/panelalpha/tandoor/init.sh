#!/bin/sh
# One-shot, run to completion before the web container is allowed to start.
#
# Two jobs, and the order they run in is the point.
#
# 1. Migrate. The image's boot.sh does this too, but it starts nginx first and
#    migrates second, so on a first boot the site is answering while the schema
#    is still being created.
# 2. Create the administrator. cookbook/views/views.py:349 renders an
#    unauthenticated form at /setup/ that makes its submitter a superuser, and
#    guards it with nothing but `User.objects.count() > 0`. On a public domain
#    that is a race between the operator and everyone else. Creating the user
#    here closes the form before anything is listening.
#
# Exiting non-zero fails the deploy: `app` depends on this with
# service_completed_successfully, so a database that never came up or an
# administrator that could not be created stops the stack instead of
# publishing a superuser form.
set -e

say() { echo "[panelalpha/init] $*"; }

cd /opt/recipes
# shellcheck disable=SC1091
. venv/bin/activate

# compose already gates this on the db healthcheck; this is the second belt,
# for the case where postgres passes pg_isready on its bootstrap socket and is
# still restarting for the real one.
attempt=0
until pg_isready -h "${POSTGRES_HOST}" -p "${POSTGRES_PORT:-5432}" -U "${POSTGRES_USER}" -q; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 60 ]; then
        say "database ${POSTGRES_HOST}:${POSTGRES_PORT:-5432} never became ready"
        exit 1
    fi
    sleep 2
done

say "migrating"
python manage.py migrate --noinput

# `manage.py shell -c` does NOT print only what the snippet prints, which cost
# a deploy: django-vite writes "Running django-vite in production mode (no
# HMR)" to stdout when the app registry loads, and plain `shell -c` prepends
# "N objects imported automatically". Read verbatim, the count came back as
# "Runningdjango-vite...0", which is not "0", so the branch below was skipped
# and the site came up with /setup/ open -- the exact failure this service
# exists to prevent, passing silently. --no-imports kills the second line and
# the last line is the only one this asks for.
#
# django-scopes does not scope auth.User, so no scopes_disabled() is needed.
users=$(python manage.py shell --no-imports -c 'from django.contrib.auth.models import User; print(User.objects.count())' 2>/dev/null | tail -n 1 | tr -dc '0-9')
say "existing users: ${users:-unreadable}"

if [ -z "${users}" ]; then
    say "could not read the user count; refusing to guess"
    exit 1
fi

if [ "${users}" = "0" ]; then
    if [ -z "${TANDOOR_ADMIN_USER:-}" ] || [ -z "${TANDOOR_ADMIN_PASSWORD:-}" ]; then
        say "no administrator credentials in the environment; refusing to leave /setup/ open"
        exit 1
    fi
    say "creating administrator ${TANDOOR_ADMIN_USER}"
    DJANGO_SUPERUSER_PASSWORD="${TANDOOR_ADMIN_PASSWORD}" \
        python manage.py createsuperuser --noinput \
        --username "${TANDOOR_ADMIN_USER}" \
        --email "${TANDOOR_ADMIN_EMAIL:-admin@localhost}"

    # createsuperuser can exit 0 without creating anything. Confirm, because
    # the only thing standing between /setup/ and the internet is this check.
    users=$(python manage.py shell --no-imports -c 'from django.contrib.auth.models import User; print(User.objects.count())' 2>/dev/null | tail -n 1 | tr -dc '0-9')
    if [ "${users:-0}" = "0" ]; then
        say "the administrator was not created; /setup/ would be open, stopping"
        exit 1
    fi
else
    # Never touched again. The password in ~/.panelalpha/tandoor/ is the one
    # this user was created with; if it was changed in the app, that is the
    # app's business and overwriting it here would be data loss.
    say "an account already exists; leaving it alone"
fi

say "done"
