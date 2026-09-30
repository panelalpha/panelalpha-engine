#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha (a redeploy
# empties ~/project, and the password is baked into the pgdata volume).
set -e

STORE_DIR="${HOME}/.panelalpha/dspace"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    (
        umask 077
        printf '# Written once on the first deploy and reused.\nPOSTGRES_PASSWORD=%s\n' "${DB_PASSWORD}" > "${DB_ENV}"
        printf '# Written once on the first deploy and reused.\ndb__P__password=%s\n' "${DB_PASSWORD}" > "${APP_ENV}"
    )
    echo "[dspace] database password written to ${STORE_DIR}" >&2
else
    echo "[dspace] reusing the database password in ${STORE_DIR}" >&2
fi
