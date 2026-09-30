#!/bin/bash
# Generates PHPLIST_SECRET and the MariaDB passwords once in ~/.panelalpha (survives redeploys).
set -e
STORE="${HOME}/.panelalpha/phplist"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
PHPLIST_SECRET=$(openssl rand -hex 32)
PHPLIST_DATABASE_PASSWORD=${db}
MARIADB_PASSWORD=${db}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
EOT
)
    echo "[panelalpha] phplist: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
