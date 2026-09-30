#!/bin/bash
# Generates Documenso's secrets once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/documenso"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
POSTGRES_PASSWORD=${pw}
NEXT_PRIVATE_DATABASE_URL=postgresql://documenso:${pw}@database:5432/documenso
NEXT_PRIVATE_DIRECT_DATABASE_URL=postgresql://documenso:${pw}@database:5432/documenso
NEXTAUTH_SECRET=$(openssl rand -hex 32)
NEXT_PRIVATE_ENCRYPTION_KEY=$(openssl rand -hex 32)
NEXT_PRIVATE_ENCRYPTION_SECONDARY_KEY=$(openssl rand -hex 32)
EOT
)
    echo "[panelalpha] documenso: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
