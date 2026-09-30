#!/bin/bash
# Generate the database password and the app's secrets once, outside ~/project
# (wiped every deploy): new ones would lock OpenPanel out of its own data.
set -e
STORE_DIR="${HOME}/.panelalpha/openpanel"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${STORE_DIR}/app.env" ] || [ ! -s "${STORE_DIR}/db.env" ]; then
    PG="$(openssl rand -hex 24)"
    DB_URL="postgresql://openpanel:${PG}@op-db:5432/openpanel?schema=public"
    (
        umask 077
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        {
            echo "DATABASE_URL=${DB_URL}"
            echo "DATABASE_URL_DIRECT=${DB_URL}"
            echo "COOKIE_SECRET=$(openssl rand -hex 32)"
            echo "ENCRYPTION_KEY=$(openssl rand -hex 32)"
        } > "${STORE_DIR}/app.env"
    )
    echo "[openpanel] database password and secrets written to ${STORE_DIR}" >&2
fi
