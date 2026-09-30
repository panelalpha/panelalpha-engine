#!/bin/bash
# Generate the database passwords once, outside ~/project (wiped every deploy).
# No MYSQL_HOST: the image defaults it to db, and in the db container it would
# send the healthcheck's mariadb client over TCP instead of the socket.
set -e
DIR="${HOME}/.panelalpha/zoneminder"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/db.env" <<ENV
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
MARIADB_USER=zmuser
MARIADB_PASSWORD=${PW}
MYSQL_USER=zmuser
MYSQL_PASSWORD=${PW}
ENV
    )
    echo "[zoneminder] generated database passwords -> ${DIR}/db.env" >&2
fi
