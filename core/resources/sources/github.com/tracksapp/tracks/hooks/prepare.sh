#!/bin/bash
# Generate the database password and SECRET_KEY_BASE once, outside ~/project
# (wiped every deploy); the image's site.yml bakes in secret_token "change-me".
set -e
DIR="${HOME}/.panelalpha/tracks"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
MARIADB_PASSWORD=${PW}
DATABASE_PASSWORD=${PW}
SECRET_KEY_BASE=$(openssl rand -hex 64)
ENV
    )
    echo "[tracks] generated database password and SECRET_KEY_BASE -> ${DIR}/app.env" >&2
fi
