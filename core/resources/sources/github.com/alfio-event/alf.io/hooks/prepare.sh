#!/bin/bash
# Generate the database password once, where a redeploy will not wipe it
# (~/project is emptied on every deploy; the password lives in the pgdata volume).
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/alfio"
DB_ENV="${STORE_DIR}/db.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ]; then
    PG_PASSWORD="$(openssl rand -hex 24)"
    (
        umask 077
        # Postgres reads the first name, alf.io's DOCKER platform the second.
        printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_ENV_POSTGRES_PASSWORD=%s\n' \
            "${PG_PASSWORD}" "${PG_PASSWORD}" > "${DB_ENV}"
    )
    echo "[alfio] database password written to ${DB_ENV}" >&2
else
    echo "[alfio] reusing ${DB_ENV}" >&2
fi
