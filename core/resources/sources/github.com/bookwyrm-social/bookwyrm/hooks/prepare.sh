#!/bin/bash
# Generate the Django SECRET_KEY and the database/Redis/Flower passwords once,
# outside ~/project (wiped every deploy): sessions and data depend on them.
set -e
DIR="${HOME}/.panelalpha/bookwyrm"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/bookwyrm.env" ]; then
    ( umask 077; cat > "${DIR}/bookwyrm.env" <<ENV
SECRET_KEY=$(openssl rand -hex 32)
POSTGRES_HOST=db
POSTGRES_DB=bookwyrm
POSTGRES_USER=bookwyrm
POSTGRES_PASSWORD=$(openssl rand -hex 24)
REDIS_ACTIVITY_PASSWORD=$(openssl rand -hex 24)
REDIS_BROKER_PASSWORD=$(openssl rand -hex 24)
FLOWER_USER=admin
FLOWER_PASSWORD=$(openssl rand -hex 16)
ENV
    )
    echo "[bookwyrm] generated SECRET_KEY and service passwords -> ${DIR}/bookwyrm.env" >&2
fi
chmod 600 "${DIR}/bookwyrm.env"
