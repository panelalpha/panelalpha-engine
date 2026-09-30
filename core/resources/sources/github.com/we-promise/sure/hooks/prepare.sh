#!/bin/bash
# Generate SECRET_KEY_BASE and the database password once, outside ~/project
# (wiped every deploy): the key also derives the app's encryption keys.
set -e
DIR="${HOME}/.panelalpha/sure"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    ( umask 077; cat > "${DIR}/app.env" <<ENV
POSTGRES_USER=sure_user
POSTGRES_DB=sure_production
POSTGRES_PASSWORD=$(openssl rand -hex 24)
SECRET_KEY_BASE=$(openssl rand -hex 64)
ENV
    )
    echo "[sure] generated SECRET_KEY_BASE and database password -> ${DIR}/app.env" >&2
fi
