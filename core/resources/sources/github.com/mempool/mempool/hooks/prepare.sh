#!/bin/bash
# Generate the MariaDB passwords once, outside ~/project (wiped every deploy).
set -e
DIR="${HOME}/.panelalpha/mempool"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    PW=$(openssl rand -hex 24)
    ( umask 077; cat > "${DIR}/db.env" <<ENV
MYSQL_DATABASE=mempool
MYSQL_USER=mempool
MYSQL_PASSWORD=${PW}
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
DATABASE_PASSWORD=${PW}
ENV
    )
    echo "[mempool] generated the database passwords -> ${DIR}/db.env" >&2
fi
