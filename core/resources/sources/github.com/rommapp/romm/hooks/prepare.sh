#!/bin/bash
# Generates the DB passwords and RomM's auth secret once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/romm"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    DB_PASS=$(openssl rand -hex 16)
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
MARIADB_PASSWORD=${DB_PASS}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 16)
DB_PASSWD=${DB_PASS}
ROMM_AUTH_SECRET_KEY=$(openssl rand -hex 32)
EOT
    )
fi
chmod 600 "${STORE}/secrets.env"

# Project environment variables (metadata provider keys) arrive in .env.
touch .env
