#!/bin/bash
# Account shell, cwd ~/project, before `compose up`. Generates the secrets once
# into ~/.panelalpha/sparkyfitness (survives redeploys; ~/project is re-cloned).
set -e
DATA="${HOME}/.panelalpha/sparkyfitness"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
SPARKY_FITNESS_DB_PASSWORD=${PGPW}
SPARKY_FITNESS_APP_DB_PASSWORD=$(openssl rand -hex 24)
# Encrypts stored API keys: must never change (64 hex, security/encryption.ts).
SPARKY_FITNESS_API_ENCRYPTION_KEY=$(openssl rand -hex 32)
# Decoded as base64 by auth.ts.
BETTER_AUTH_SECRET=$(openssl rand -base64 32)
ENVEOF
    echo "[panelalpha] sparkyfitness: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
