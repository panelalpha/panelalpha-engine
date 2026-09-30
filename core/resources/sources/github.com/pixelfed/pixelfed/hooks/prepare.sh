#!/bin/bash
# Generate APP_KEY and the database passwords once, outside ~/project (wiped
# every deploy): encrypted data and sessions depend on APP_KEY.
set -e
DIR="${HOME}/.panelalpha/pixelfed"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=${PW}
MARIADB_PASSWORD=${PW}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
ENV
    )
    echo "[pixelfed] generated APP_KEY and database passwords -> ${DIR}/app.env" >&2
fi
