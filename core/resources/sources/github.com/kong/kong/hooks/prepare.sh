#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha/kong/ (~/project is
# wiped on every deploy; the database volume keeps the first password).
set -e

STORE="${HOME}/.panelalpha/kong"
DB_ENV="${STORE}/db.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${DB_ENV}" ]; then
    PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\nKONG_PG_PASSWORD=%s\n' "${PW}" "${PW}" > "${DB_ENV}"
    )
    echo "[panelalpha] kong: generated the database password" >&2
fi
chmod 600 "${DB_ENV}"
