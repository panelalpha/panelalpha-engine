#!/bin/bash
# Generates the encryption and throttle keys and MariaDB passwords once in ~/.panelalpha (survives redeploys).
set -e
STORE="${HOME}/.panelalpha/opensourcepos"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
ENCRYPTION_KEY=$(openssl rand -hex 32)
THROTTLE_KEY=$(openssl rand -hex 32)
MYSQL_PASSWORD=${db}
MARIADB_PASSWORD=${db}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
EOT
)
    echo "[panelalpha] opensourcepos: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
