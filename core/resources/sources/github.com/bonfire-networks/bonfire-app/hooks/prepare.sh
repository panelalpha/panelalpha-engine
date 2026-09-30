#!/bin/bash
# Generate Phoenix secrets and the database password once, outside ~/project
# (wiped every deploy): sessions, encrypted fields and the DB depend on them.
set -e
DIR="${HOME}/.panelalpha/bonfire"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/bonfire.env" ]; then
    ( umask 077; cat > "${DIR}/bonfire.env" <<ENV
SECRET_KEY_BASE=$(openssl rand -hex 64)
SIGNING_SALT=$(openssl rand -hex 24)
ENCRYPTION_SALT=$(openssl rand -hex 24)
RELEASE_COOKIE=$(openssl rand -hex 24)
POSTGRES_HOST=db
POSTGRES_USER=postgres
POSTGRES_DB=bonfire_db
POSTGRES_PASSWORD=$(openssl rand -hex 24)
ENV
    )
    echo "[bonfire] generated secrets and DB password -> ${DIR}/bonfire.env" >&2
fi
chmod 600 "${DIR}/bonfire.env"
