#!/bin/bash
# Generates the secrets once into ~/.panelalpha/flagsmith (survives redeploys;
# ~/project is re-cloned on every deploy).
set -e
DATA="${HOME}/.panelalpha/flagsmith"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
DATABASE_URL=postgresql://postgres:${PGPW}@postgres:5432/flagsmith
# Signs sessions and tokens: must not change between deploys.
DJANGO_SECRET_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] flagsmith: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
