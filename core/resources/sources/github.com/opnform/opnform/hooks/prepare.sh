#!/bin/bash
# Generates APP_KEY, JWT_SECRET, the API/front shared secret and the DB password once
# in ~/.panelalpha (survives redeploys), as upstream's scripts/setup-env.sh does.
set -e
STORE="${HOME}/.panelalpha/opnform"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    shared="$(openssl rand -hex 20)"
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
APP_KEY=base64:$(openssl rand -base64 32)
JWT_SECRET=$(openssl rand -hex 20)
FRONT_API_SECRET=${shared}
NUXT_API_SECRET=${shared}
DB_PASSWORD=${db}
POSTGRES_PASSWORD=${db}
EOT
)
    echo "[panelalpha] opnform: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
