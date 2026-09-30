#!/bin/bash
# Generates the database password and Django SECRET_KEY once into
# ~/.panelalpha/mataroa/ (~/project is wiped on every deploy; a rotated DB
# password would lock the app out of its surviving database volume).
set -e

STORE="${HOME}/.panelalpha/mataroa"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    DB_PASS="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASS}" > "${STORE}/db.env"
        printf 'SECRET_KEY=%s\nDATABASE_URL=postgres://mataroa:%s@db:5432/mataroa\n' \
            "$(openssl rand -hex 32)" "${DB_PASS}" > "${STORE}/app.env"
    )
    echo "[mataroa] generated secrets in ${STORE}"
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
