#!/bin/bash
# Account shell, cwd ~/project, before `compose up`. Generates the secrets once
# into ~/.panelalpha/changerawr (survives redeploys; ~/project is re-cloned).
set -e
DATA="${HOME}/.panelalpha/changerawr"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
DATABASE_URL=postgresql://changerawr:${PGPW}@postgres:5432/changerawr?schema=public
JWT_ACCESS_SECRET=$(openssl rand -hex 32)
# 64 hex = the 32-byte AES key lib/utils/encryption.ts expects.
GITHUB_ENCRYPTION_KEY=$(openssl rand -hex 32)
# base64 of 32 bytes (lib/custom-domains/ssl/encryption.ts).
ENCRYPTION_KEY=$(openssl rand -base64 32)
ANALYTICS_SALT=$(openssl rand -hex 32)
INTERNAL_API_SECRET=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] changerawr: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
