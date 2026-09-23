#!/bin/sh
# One-shot, run to completion before the app container is allowed to start.
# Two jobs: migrate the database (which creates the postgis/pg_trgm/unaccent
# extensions and the whole schema), and seed a confirmed administrator so the
# owner can log in without an email round-trip. `app` waits on this with
# service_completed_successfully, so a failure here stops the deploy instead of
# publishing a broken site.
set -e

say() { echo "[panelalpha/init] $*"; }

DB_HOST="${MOBILIZON_DATABASE_HOST:-db}"
DB_PORT="${MOBILIZON_DATABASE_PORT:-5432}"
DB_USER="${MOBILIZON_DATABASE_USERNAME:-mobilizon}"
DB_NAME="${MOBILIZON_DATABASE_DBNAME:-mobilizon}"

# compose already gates on the db healthcheck; this is a second belt for the
# window where postgres answers on its bootstrap socket while still restarting.
say "waiting for database ${DB_HOST}:${DB_PORT}"
i=0
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -q -t 1; do
    i=$((i + 1))
    if [ "${i}" -ge 60 ]; then
        say "database never became ready"
        exit 1
    fi
    sleep 2
done

# migrate runs the prerequites migration first, which does
# `CREATE EXTENSION IF NOT EXISTS postgis` (and pg_trgm, unaccent). The postgis
# image's POSTGRES_USER is a superuser, so the extension creates cleanly. The
# app's own entrypoint will re-run migrate as a no-op once it starts.
say "running migrations"
/bin/mobilizon_ctl migrate

# Derive the admin email from the account's public host (admin@<host>). It is
# only a login identifier -- the account is created already-confirmed and never
# receives mail -- so a bare host with no dot is fine (Mobilizon's email regex
# accepts admin@localhost).
url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"
host="${host%%/*}"
[ -n "${host}" ] || host="localhost"
ADMIN_EMAIL="${MOBILIZON_ADMIN_EMAIL:-admin@${host}}"

if [ -z "${MOBILIZON_ADMIN_PASSWORD:-}" ]; then
    say "no MOBILIZON_ADMIN_PASSWORD in the store; refusing to seed a blank-password admin"
    exit 1
fi

# Idempotent AND domain-change-safe: seed only when NO administrator exists yet,
# regardless of email. role is the Postgres user_role enum, so it compares to
# the string literal. A rebuild, or a later domain change, seeds nobody.
admins="$(PGPASSWORD="${MOBILIZON_DATABASE_PASSWORD}" psql -tA \
    -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" \
    -c "SELECT count(*) FROM users WHERE role = 'administrator';" 2>/dev/null || echo "")"

if [ "${admins}" = "0" ]; then
    say "seeding administrator ${ADMIN_EMAIL}"
    # RPC disabled by the compose env, so this runs in its own eval instance
    # (serve_endpoints=false, no port bind). --admin sets confirmed_at=now with
    # no confirmation token; the profile lets the admin create events at once.
    MOBILIZON_CTL_RPC_DISABLED=true /bin/mobilizon_ctl users.new "${ADMIN_EMAIL}" \
        -p "${MOBILIZON_ADMIN_PASSWORD}" \
        --admin \
        --profile-username admin \
        --profile-display-name Administrator
    say "administrator seeded"
elif [ -z "${admins}" ]; then
    say "could not query for existing administrators"
    exit 1
else
    say "an administrator already exists (${admins}); leaving it untouched"
fi

say "done"
