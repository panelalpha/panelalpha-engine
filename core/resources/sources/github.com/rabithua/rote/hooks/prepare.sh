#!/bin/bash
# Generates the Postgres password once into ~/.panelalpha (a redeploy empties ~/project).
set -e
STORE_DIR="${HOME}/.panelalpha/rote"
APP_ENV="${STORE_DIR}/app.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${APP_ENV}" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; printf 'POSTGRES_PASSWORD=%s\nPOSTGRESQL_URL=postgresql://rote:%s@rote-postgres:5432/rote\n' "$PW" "$PW" > "${APP_ENV}" )
    echo "[rote] database password written to ${STORE_DIR}" >&2
else
    echo "[rote] reusing ${APP_ENV}" >&2
fi
