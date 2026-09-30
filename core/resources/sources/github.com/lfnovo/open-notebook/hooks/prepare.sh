#!/bin/bash
# Generates the SurrealDB password and the key that encrypts stored provider
# API keys once into ~/.panelalpha (~/project is re-cloned on every deploy).
set -e
DATA="${HOME}/.panelalpha/open-notebook"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PASS=$(openssl rand -hex 24)
    cat > "${ENV_FILE}" <<ENVEOF
SURREAL_USER=root
SURREAL_PASS=${PASS}
SURREAL_PASSWORD=${PASS}
OPEN_NOTEBOOK_ENCRYPTION_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] open-notebook: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
touch ~/project/.env
