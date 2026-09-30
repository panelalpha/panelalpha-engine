#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha (~/project is
# re-cloned on every deploy; the database volume keeps the first password).
set -e
DATA="${HOME}/.panelalpha/evolution-api"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PASS=$(openssl rand -hex 24)
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PASS}
DATABASE_CONNECTION_URI=postgresql://evolution:${PASS}@postgres:5432/evolution_db?schema=evolution_api
ENVEOF
    echo "[panelalpha] evolution-api: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
