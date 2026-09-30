#!/bin/bash
# Generates APP_KEY and the MySQL passwords once in ~/.panelalpha (survives redeploys).
set -e
STORE="${HOME}/.panelalpha/invoiceninja"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=${db}
MYSQL_PASSWORD=${db}
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
EOT
)
    echo "[panelalpha] invoiceninja: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"

# The image does not read the repo's .env; this keeps .env.example (whose
# COMPOSER_AUTH compose cannot parse once re-quoted, engine#326) out of it.
printf '# Invoice Ninja runs from the official image; settings are in docker-compose.yml.\n' > ~/project/.env
