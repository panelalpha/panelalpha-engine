#!/bin/bash
# Generate Infisical's root keys and the database password once into ~/.panelalpha
# (~/project is re-cloned on every deploy; secrets stored in the database need the same keys).
set -e
DATA="${HOME}/.panelalpha/infisical"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${DATA}/app.env" ]; then
    umask 077
    PG=$(openssl rand -hex 24)
    printf 'POSTGRES_PASSWORD=%s\n' "${PG}" > "${DATA}/db.env"
    cat > "${DATA}/app.env" <<ENVEOF
ENCRYPTION_KEY=$(openssl rand -hex 16)
AUTH_SECRET=$(openssl rand -base64 32)
DB_CONNECTION_URI=postgres://infisical:${PG}@db:5432/infisical
ENVEOF
    echo "[panelalpha] infisical: generated secrets in ${DATA}"
fi
chmod 600 "${DATA}"/*.env
