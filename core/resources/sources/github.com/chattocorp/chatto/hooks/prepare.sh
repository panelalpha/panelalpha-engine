#!/bin/bash
# Generates Chatto's required secrets once in ~/.panelalpha (survives redeploys;
# a new core key would invalidate sessions and invite links).
set -e
STORE="${HOME}/.panelalpha/chatto"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; cat > "${STORE}/app.env" <<EOT
CHATTO_WEBSERVER_COOKIE_SIGNING_SECRET=$(openssl rand -hex 32)
CHATTO_WEBSERVER_COOKIE_ENCRYPTION_SECRET=$(openssl rand -hex 32)
CHATTO_CORE_SECRET_KEY=$(openssl rand -hex 32)
CHATTO_CORE_ASSETS_SIGNING_SECRET=$(openssl rand -hex 32)
EOT
)
    echo "[panelalpha] chatto: generated secrets into ${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
