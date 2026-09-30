#!/bin/bash
# Generate the database password and Tinode's token/UID keys once, outside
# ~/project (wiped every deploy). The UID key must stay stable against the data.
set -e
STORE_DIR="${HOME}/.panelalpha/tinode"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ]; then
    PG="$(openssl rand -hex 24)"
    (
        umask 077
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        {
            echo "POSTGRES_DSN=postgresql://postgres:${PG}@database:5432/tinode?sslmode=disable&connect_timeout=10"
            echo "AUTH_TOKEN_KEY=$(openssl rand -base64 32)"
            echo "UID_ENCRYPTION_KEY=$(openssl rand -base64 16)"
        } > "${STORE_DIR}/app.env"
    )
    echo "[tinode] secrets written to ${STORE_DIR}" >&2
fi
