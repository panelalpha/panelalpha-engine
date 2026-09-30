#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha (~/project is
# re-cloned on every deploy; the database volume keeps the first password).
set -e
DATA="${HOME}/.panelalpha/oxicloud"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PASS=$(openssl rand -hex 24)
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PASS}
OXICLOUD_DB_CONNECTION_STRING=postgres://postgres:${PASS}@postgres:5432/oxicloud
ENVEOF
    echo "[panelalpha] oxicloud: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
touch ~/project/.env
