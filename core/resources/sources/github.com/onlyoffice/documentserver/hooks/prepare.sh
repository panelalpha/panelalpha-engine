#!/bin/bash
# Generate the JWT secret once, outside ~/project (wiped every deploy), so the
# storage apps integrated with this server keep working across redeploys.
set -e
STORE_DIR="${HOME}/.panelalpha/onlyoffice"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ]; then
    (
        umask 077
        echo "JWT_SECRET=$(openssl rand -hex 32)" > "${STORE_DIR}/app.env"
    )
    echo "[onlyoffice] JWT secret written to ${STORE_DIR}/app.env" >&2
fi
