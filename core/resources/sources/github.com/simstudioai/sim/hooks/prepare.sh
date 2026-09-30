#!/bin/bash
# Generates Sim's secrets and the DB password once in ~/.panelalpha (survives
# redeploys). ENCRYPTION_KEY cannot change later without losing stored credentials.
set -e
STORE="${HOME}/.panelalpha/sim"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/app.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/app.env" <<EOT
POSTGRES_PASSWORD=${db}
DATABASE_URL=postgresql://postgres:${db}@db:5432/simstudio
BETTER_AUTH_SECRET=$(openssl rand -hex 32)
ENCRYPTION_KEY=$(openssl rand -hex 32)
INTERNAL_API_SECRET=$(openssl rand -hex 32)
CRON_SECRET=$(openssl rand -hex 32)
EOT
)
    echo "[panelalpha] sim: generated secrets into ${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
