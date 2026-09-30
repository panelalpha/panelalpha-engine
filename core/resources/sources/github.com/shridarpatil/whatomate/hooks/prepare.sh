#!/bin/bash
# Generates the JWT secret, the encryption key and the DB password once in
# ~/.panelalpha (survives redeploys; a new encryption key would orphan stored secrets).
set -e
STORE="${HOME}/.panelalpha/whatomate"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/app.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/app.env" <<EOT
WHATOMATE_JWT__SECRET=$(openssl rand -hex 32)
WHATOMATE_APP__ENCRYPTION_KEY=$(openssl rand -hex 32)
WHATOMATE_DATABASE__PASSWORD=${db}
POSTGRES_PASSWORD=${db}
EOT
)
    echo "[panelalpha] whatomate: generated secrets into ${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
