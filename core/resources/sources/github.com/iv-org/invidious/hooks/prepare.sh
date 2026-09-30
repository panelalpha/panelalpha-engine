#!/bin/bash
# Generates Invidious' HMAC key, companion key and DB password once in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/invidious"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    pw="$(openssl rand -hex 24)"
    companion="$(openssl rand -hex 8)"   # Invidious requires exactly 16 characters
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
POSTGRES_PASSWORD=${pw}
INVIDIOUS_DATABASE_URL=postgres://kemal:${pw}@invidious-db:5432/invidious
INVIDIOUS_HMAC_KEY=$(openssl rand -hex 32)
INVIDIOUS_INVIDIOUS_COMPANION_KEY=${companion}
SERVER_SECRET_KEY=${companion}
EOT
)
    echo "[panelalpha] invidious: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
