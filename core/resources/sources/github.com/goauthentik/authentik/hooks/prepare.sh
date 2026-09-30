#!/bin/bash
# AUTHENTIK_SECRET_KEY signs sessions and encrypts stored secrets, and the
# postgres volume keeps the first DB password: both must survive a deploy,
# so they live in ~/.panelalpha, not ~/project.
set -e
STORE_DIR="${HOME}/.panelalpha/authentik"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${DB_ENV}" ] || [ ! -s "${APP_ENV}" ]; then
    PW=$(openssl rand -hex 24)
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${DB_ENV}"
     printf 'AUTHENTIK_POSTGRESQL__PASSWORD=%s\nAUTHENTIK_SECRET_KEY=%s\n' "${PW}" "$(openssl rand -base64 60 | tr -d '\n')" > "${APP_ENV}")
    echo "[authentik] generated secrets in ${STORE_DIR}" >&2
else
    echo "[authentik] reusing ${STORE_DIR}" >&2
fi
