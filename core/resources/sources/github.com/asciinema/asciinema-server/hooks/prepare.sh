#!/bin/bash
# Generate SECRET_KEY_BASE and the database password once, outside ~/project
# (wiped every deploy): sessions and login tokens are signed with the key.
set -e
DIR="${HOME}/.panelalpha/asciinema"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
POSTGRES_PASSWORD=${PW}
DATABASE_URL=postgresql://asciinema:${PW}@db/asciinema
SECRET_KEY_BASE=$(openssl rand -hex 48)
ENV
    )
    echo "[asciinema] generated SECRET_KEY_BASE and database password -> ${DIR}/app.env" >&2
fi
