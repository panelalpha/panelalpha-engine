#!/bin/bash
# Generates the database password and SECRET_KEY_BASE once into
# ~/.panelalpha/keila/ (survives redeploys; ~/project is wiped every deploy).
set -e
STORE="${HOME}/.panelalpha/keila"
DB_ENV="${STORE}/db.env"
APP_ENV="${STORE}/app.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    pw="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${pw}" > "${DB_ENV}"
        printf 'DB_URL=postgres://keila:%s@db:5432/keila\nSECRET_KEY_BASE=%s\n' \
            "${pw}" "$(openssl rand -hex 48)" > "${APP_ENV}"
    )
    echo "[panelalpha] keila: generated the database password and SECRET_KEY_BASE" >&2
fi
chmod 600 "${DB_ENV}" "${APP_ENV}"
