#!/bin/bash
# Generates the secrets once into ~/.panelalpha/kaneo (survives redeploys;
# ~/project is re-cloned on every deploy).
set -e
DATA="${HOME}/.panelalpha/kaneo"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=$(openssl rand -hex 24)
# Signs sessions; unset, the image makes a new one on every boot.
AUTH_SECRET=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] kaneo: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
