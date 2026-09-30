#!/bin/bash
# Generate the data encryption key and database password once into
# ~/.panelalpha (~/project is re-cloned on every deploy; the database keeps them).
set -e
DATA="${HOME}/.panelalpha/hoppscotch"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PASS=$(openssl rand -hex 24)
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PASS}
DATABASE_URL=postgresql://hoppscotch:${PASS}@db:5432/hoppscotch
DATA_ENCRYPTION_KEY=$(openssl rand -hex 16)
ENVEOF
    echo "[panelalpha] hoppscotch: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
touch ~/project/.env
