#!/bin/sh
# usage: mergestat-run.sh <postgres|worker|graphql|ui> [the service's command...]
# Puts the engine's login (MERGESTAT_DB_USER / MERGESTAT_DB_PASSWORD) and the
# account's secrets (MERGESTAT_JWT_SECRET / MERGESTAT_ENCRYPTION_SECRET, both
# from env_file) where each MergeStat service reads them, in place of the
# compose file's published defaults, then execs the image's own entrypoint.
# The generated password and secrets are letters and digits, so they go into a
# connection URL as they are.
set -e

say() { echo "[panelalpha] mergestat: $*" >&2; }

service="$1"
shift

user="${MERGESTAT_DB_USER:-postgres}"
if [ -z "${MERGESTAT_DB_PASSWORD:-}" ] || [ -z "${MERGESTAT_JWT_SECRET:-}" ] || [ -z "${MERGESTAT_ENCRYPTION_SECRET:-}" ]; then
    say "the login or the secrets are missing from the environment; refusing to start on the published defaults"
    exit 1
fi
connection="postgres://${user}:${MERGESTAT_DB_PASSWORD}@postgres:5432/postgres?sslmode=disable"
ENCRYPTION_SECRET="${MERGESTAT_ENCRYPTION_SECRET}"
export ENCRYPTION_SECRET

case "${service}" in
    postgres)
        # Used by the image only when it creates the database (a new volume).
        POSTGRES_USER="${user}"
        POSTGRES_PASSWORD="${MERGESTAT_DB_PASSWORD}"
        export POSTGRES_USER POSTGRES_PASSWORD
        exec docker-entrypoint.sh "$@"
        ;;
    worker)
        # An account deployed before this recipe keeps its database, created
        # with the published password: move it to the generated one, once.
        if ! PGPASSWORD="${MERGESTAT_DB_PASSWORD}" psql -h postgres -U "${user}" -d postgres -tAc 'select 1' >/dev/null 2>&1 \
            && PGPASSWORD=password psql -h postgres -U "${user}" -d postgres -tAc 'select 1' >/dev/null 2>&1; then
            PGPASSWORD=password psql -h postgres -U "${user}" -d postgres -q \
                -c "ALTER USER \"${user}\" PASSWORD '${MERGESTAT_DB_PASSWORD}'"
            say "the database still had the published password; it now has the generated one"
        fi
        POSTGRES_CONNECTION="${connection}"
        export POSTGRES_CONNECTION
        exec /sbin/tini /worker "$@"
        ;;
    graphql)
        # The compose file's own PostGraphile flags, with its connection string
        # and --jwt-secret replaced.
        for arg in "$@"; do
            shift
            case "${arg}" in
                postgres://*) set -- "$@" "${connection}" ;;
                --jwt-secret=*) set -- "$@" "--jwt-secret=${MERGESTAT_JWT_SECRET}" ;;
                *) set -- "$@" "${arg}" ;;
            esac
        done
        DISPLAY_PG_USER="${user}"
        export DISPLAY_PG_USER
        exec postgraphile -n 0.0.0.0 "$@"
        ;;
    ui)
        POSTGRES_CONNECTION="${connection}"
        JWT_SECRET="${MERGESTAT_JWT_SECRET}"
        export POSTGRES_CONNECTION JWT_SECRET
        exec docker-entrypoint.sh "$@"
        ;;
    *)
        say "unknown service ${service}"
        exit 1
        ;;
esac
