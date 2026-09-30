#!/bin/bash
# Generates the app's secrets once into ~/.panelalpha/trackwatch/ (~/project is
# wiped on every deploy; a new DB password would lock the app out of its volume).
set -e
STORE="${HOME}/.panelalpha/trackwatch"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASSWORD}" > "${STORE}/db.env"
        printf 'DATABASE_PASSWORD=%s\nSECRET_KEY=%s\nADMIN_KEY=%s\n' \
            "${DB_PASSWORD}" "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" > "${STORE}/app.env"
    )
    echo "[trackwatch] secrets written to ${STORE}" >&2
fi
# The services read the project's env vars from .env; make sure it exists.
touch "${HOME}/project/.env"
