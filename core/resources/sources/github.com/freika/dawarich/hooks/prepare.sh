#!/bin/bash
# Generate SECRET_KEY_BASE and the database password once, outside ~/project
# (wiped every deploy): sessions are signed with the key.
set -e
DIR="${HOME}/.panelalpha/dawarich"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
POSTGRES_PASSWORD=${PW}
DATABASE_PASSWORD=${PW}
SECRET_KEY_BASE=$(openssl rand -hex 64)
ENV
    )
    echo "[dawarich] generated SECRET_KEY_BASE and database password -> ${DIR}/app.env" >&2
fi
