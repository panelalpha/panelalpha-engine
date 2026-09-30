#!/bin/bash
# Generate the database password once, outside ~/project (wiped every deploy);
# it is baked into the pgdata volume on the first start.
set -e
STORE_DIR="${HOME}/.panelalpha/halo"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/db.env" ]; then
    PW="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\nSPRING_R2DBC_PASSWORD=%s\n' "$PW" "$PW" > "${STORE_DIR}/db.env"
    )
    echo "[halo] database password written to ${STORE_DIR}/db.env" >&2
fi
