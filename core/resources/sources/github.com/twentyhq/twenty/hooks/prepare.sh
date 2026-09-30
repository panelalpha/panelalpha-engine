#!/bin/bash
# Generates the secrets once into ~/.panelalpha/twenty (survives redeploys;
# ~/project is re-cloned on every deploy).
set -e
DATA="${HOME}/.panelalpha/twenty"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
PG_DATABASE_URL=postgres://twenty:${PGPW}@db:5432/twenty
# Encrypts stored credentials and signs tokens: must never change.
ENCRYPTION_KEY=$(openssl rand -base64 32)
ENVEOF
    echo "[panelalpha] twenty: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
