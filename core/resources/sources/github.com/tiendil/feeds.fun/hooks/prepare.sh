#!/bin/bash
# Generate the database password and the key that encrypts users' stored API
# keys once, outside ~/project (wiped every deploy); upstream's example ships
# a public key. New values would lock Feeds Fun out of its own data.
set -e
STORE_DIR="${HOME}/.panelalpha/feeds-fun"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${STORE_DIR}/app.env" ] || [ ! -s "${STORE_DIR}/db.env" ]; then
    PG="$(openssl rand -hex 24)"
    (
        umask 077
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        {
            echo "FFUN_POSTGRESQL__PASSWORD=${PG}"
            # Fernet key: 32 url-safe base64-encoded bytes
            echo "FFUN_USER_SETTINGS_SECRET_KEY=$(openssl rand -base64 32 | tr '+/' '-_')"
        } > "${STORE_DIR}/app.env"
    )
    echo "[feeds-fun] database password and settings key written to ${STORE_DIR}" >&2
fi
