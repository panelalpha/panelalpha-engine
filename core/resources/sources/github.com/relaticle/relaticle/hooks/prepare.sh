#!/bin/bash
# Generates APP_KEY and the database password once in ~/.panelalpha (survives
# redeploys; ~/project does not). A new APP_KEY would orphan encrypted data.
set -e
STORE="${HOME}/.panelalpha/relaticle"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/app.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/app.env" <<EOT
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=${db}
POSTGRES_PASSWORD=${db}
EOT
)
    echo "[panelalpha] relaticle: generated secrets into ${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
