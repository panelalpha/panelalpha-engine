#!/bin/bash
# Account shell, cwd ~/project, before `compose up`. Generates the secrets once
# into ~/.panelalpha/litellm (survives redeploys; ~/project is re-cloned).
set -e
DATA="${HOME}/.panelalpha/litellm"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
DATABASE_URL=postgresql://litellm:${PGPW}@postgres:5432/litellm
# Admin key for the API and the UI login (user "admin"), as upstream's quickstart generates it.
LITELLM_MASTER_KEY=sk-$(openssl rand -hex 32)
# Encrypts provider credentials stored in the database: must never change.
LITELLM_SALT_KEY=sk-$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] litellm: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
