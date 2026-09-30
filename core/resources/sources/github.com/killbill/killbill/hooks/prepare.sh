#!/bin/bash
# Generate the MariaDB root password once, outside ~/project (wiped every
# deploy); it is baked into the db volume on the first start.
set -e
STORE_DIR="${HOME}/.panelalpha/killbill"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/db.env" ]; then
    PW="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'MYSQL_ROOT_PASSWORD=%s\nKILLBILL_DAO_PASSWORD=%s\nKAUI_CONFIG_DAO_PASSWORD=%s\n' "$PW" "$PW" "$PW" > "${STORE_DIR}/db.env"
    )
    echo "[killbill] database password written to ${STORE_DIR}/db.env" >&2
fi
