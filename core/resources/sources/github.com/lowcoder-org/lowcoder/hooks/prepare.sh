#!/bin/bash
# Generate Lowcoder's secrets once, outside ~/project (wiped every deploy); the
# encryption password and salt protect datasource credentials stored in MongoDB.
set -e
STORE_DIR="${HOME}/.panelalpha/lowcoder"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/secrets.env" ]; then
    (
        umask 077
        {
            printf 'LOWCODER_DB_ENCRYPTION_PASSWORD=%s\n' "$(openssl rand -hex 24)"
            printf 'LOWCODER_DB_ENCRYPTION_SALT=%s\n' "$(openssl rand -hex 24)"
            printf 'LOWCODER_API_KEY_SECRET=%s\n' "$(openssl rand -hex 32)"
            printf 'LOWCODER_NODE_SERVICE_SECRET=%s\n' "$(openssl rand -hex 32)"
            printf 'LOWCODER_NODE_SERVICE_SECRET_SALT=%s\n' "$(openssl rand -hex 24)"
        } > "${STORE_DIR}/secrets.env"
    )
    echo "[lowcoder] secrets written to ${STORE_DIR}/secrets.env" >&2
fi
