#!/bin/bash
# Generates the Postgres password and BETTER_AUTH_SECRET once into ~/.panelalpha
# (a redeploy empties ~/project).
set -e
STORE_DIR="${HOME}/.panelalpha/metamcp"
APP_ENV="${STORE_DIR}/app.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${APP_ENV}" ]; then
    PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\nDATABASE_URL=postgresql://metamcp_user:%s@postgres:5432/metamcp_db\nBETTER_AUTH_SECRET=%s\n' \
            "$PW" "$PW" "$(openssl rand -hex 32)" > "${APP_ENV}"
    )
    echo "[metamcp] secrets written to ${STORE_DIR}" >&2
else
    echo "[metamcp] reusing ${APP_ENV}" >&2
fi
