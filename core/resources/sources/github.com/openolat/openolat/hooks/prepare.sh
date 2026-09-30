#!/bin/bash
# Generate the PostgreSQL password once, outside ~/project (wiped every
# deploy); the database keeps it in its volume.
set -e
STORE_DIR="${HOME}/.panelalpha/openolat"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/openolat.env" ]; then
    (
        umask 077
        echo "POSTGRES_PASSWORD=$(openssl rand -hex 24)" > "${STORE_DIR}/openolat.env"
    )
    echo "[openolat] secrets written to ${STORE_DIR}/openolat.env" >&2
fi
