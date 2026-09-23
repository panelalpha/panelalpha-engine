#!/bin/sh
# One-shot, run to completion before the API is allowed to serve.
#
#   1. Migrate. The image's gunicorn does not migrate, so without this the API
#      answers every query with a missing-relation error.
#   2. Publish the collected static. `collectstatic` writes into STATIC_ROOT,
#      a volume the front mounts read-only and serves at /staticfiles/.
#   3. Create this account's superuser, idempotently.
#
# Exiting non-zero fails the deploy: api/worker/beat depend on this with
# service_completed_successfully.
set -e

say() { echo "[panelalpha/init] $*"; }

cd /app

# Compose already gated on the db healthcheck; this is the second belt, for the
# window where postgres passes pg_isready on its bootstrap socket and is still
# coming up for real.
attempt=0
until funkwhale-manage showmigrations >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 60 ]; then
        say "database never accepted a connection"
        funkwhale-manage showmigrations 2>&1 | tail -n 5 || true
        exit 1
    fi
    sleep 2
done

say "migrating"
funkwhale-manage migrate --noinput

say "collecting static files"
funkwhale-manage collectstatic --noinput --verbosity 0

mkdir -p /data/media

# ---------------------------------------------------------------------------
# The superuser.
#
# Funkwhale ships no default account; its API is unusable until one exists, and
# the first person to register an open pod would otherwise become its owner.
# `fw users create` is the documented path -- it runs the same serializer the
# web signup does and marks the email pre-verified for a CLI-created account.
count_supers() {
    funkwhale-manage shell -c \
        'from django.contrib.auth import get_user_model; print(get_user_model().objects.filter(is_superuser=True).count())' \
        2>/dev/null | tail -n 1 | tr -dc '0-9'
}

supers="$(count_supers)"
say "existing superusers: ${supers:-unreadable}"
if [ -z "${supers}" ]; then
    say "could not read the user count; refusing to guess"
    exit 1
fi

if [ "${supers}" = "0" ]; then
    if [ -z "${FUNKWHALE_ADMIN_USERNAME:-}" ] || [ -z "${FUNKWHALE_ADMIN_EMAIL:-}" ] || [ -z "${FUNKWHALE_CLI_USER_PASSWORD:-}" ]; then
        say "no administrator credentials in the environment; refusing to deploy a pod with no owner"
        exit 1
    fi
    say "creating superuser ${FUNKWHALE_ADMIN_USERNAME}"
    # FUNKWHALE_CLI_USER_PASSWORD is the click option's envvar, so the password
    # is not on the command line or in the process table.
    funkwhale-manage fw users create \
        --username "${FUNKWHALE_ADMIN_USERNAME}" \
        --email "${FUNKWHALE_ADMIN_EMAIL}" \
        --superuser

    supers="$(count_supers)"
    if [ "${supers:-0}" = "0" ]; then
        say "the superuser was not created; stopping rather than publishing an unowned pod"
        exit 1
    fi
    say "superuser created"
else
    # Never touched again. If the owner changed the password through the app,
    # that is theirs; the note in ~/.panelalpha/funkwhale/ is then stale and the
    # app wins.
    say "a superuser already exists; leaving it alone"
fi

say "done"
