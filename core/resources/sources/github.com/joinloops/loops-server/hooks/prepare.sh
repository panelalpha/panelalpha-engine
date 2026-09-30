#!/bin/bash
# Generate APP_KEY and the database/Redis passwords once, outside ~/project
# (wiped every deploy): sessions and encrypted data depend on APP_KEY.
set -e
DIR="${HOME}/.panelalpha/loops"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=${PW}
MYSQL_PASSWORD=${PW}
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
REDIS_PASSWORD=$(openssl rand -hex 24)
ENV
    )
    echo "[loops] generated APP_KEY and database/Redis passwords -> ${DIR}/app.env" >&2
fi
chmod 600 "${DIR}/app.env"
