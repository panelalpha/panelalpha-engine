#!/bin/bash
# Generate the database password once, outside ~/project (wiped every deploy):
# a new one would lock Pleroma out of the existing postgres volume.
set -e
STORE_DIR="${HOME}/.panelalpha/pleroma"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ] || [ ! -f "${STORE_DIR}/db.env" ]; then
    PG="$(openssl rand -hex 24)"
    (
        umask 077
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        echo "DB_PASS=${PG}" > "${STORE_DIR}/app.env"
    )
    echo "[pleroma] database password written to ${STORE_DIR}" >&2
fi
