#!/bin/bash
# Generates the instance's own secrets once into ~/.panelalpha/authorizer
# (~/project is re-cloned on every deploy; tokens and TOTP seeds depend on them).
set -e
DATA="${HOME}/.panelalpha/authorizer"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    cat > "${ENV_FILE}" <<ENVEOF
AUTHORIZER_CLIENT_ID=$(cat /proc/sys/kernel/random/uuid)
AUTHORIZER_CLIENT_SECRET=$(openssl rand -hex 32)
AUTHORIZER_JWT_SECRET=$(openssl rand -hex 32)
AUTHORIZER_ENCRYPTION_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] authorizer: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
# .env then holds only the project's own env vars.
touch ~/project/.env
