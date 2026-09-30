#!/bin/bash
# Generate NEXTAUTH_SECRET and the Meilisearch key once into ~/.panelalpha
# (~/project is re-cloned on every deploy; sessions and the index keep them).
set -e
DATA="${HOME}/.panelalpha/karakeep"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    cat > "${ENV_FILE}" <<ENVEOF
NEXTAUTH_SECRET=$(openssl rand -hex 32)
MEILI_MASTER_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] karakeep: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
touch ~/project/.env
