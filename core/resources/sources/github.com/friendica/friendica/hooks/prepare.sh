#!/bin/bash
# Generate the database passwords once, outside ~/project (wiped every deploy).
set -e
DIR="${HOME}/.panelalpha/friendica"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/db.env" <<ENV
MARIADB_PASSWORD=${PW}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
MYSQL_PASSWORD=${PW}
ENV
    )
    echo "[friendica] generated database passwords -> ${DIR}/db.env" >&2
fi
