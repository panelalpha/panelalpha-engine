#!/bin/bash
# Generate the PostgreSQL password once, outside ~/project (wiped every
# deploy); the database keeps it in its volume.
set -e
STORE_DIR="${HOME}/.panelalpha/guacamole"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/db.env" ]; then
    PG="$(openssl rand -hex 24)"
    (
        umask 077
        # Two files: the guacamole image rewrites any POSTGRES_* variable it
        # sees and blanks POSTGRESQL_PASSWORD in the process (1.6.0).
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        echo "POSTGRESQL_PASSWORD=${PG}" > "${STORE_DIR}/app.env"
    )
    echo "[guacamole] secrets written to ${STORE_DIR}" >&2
fi
