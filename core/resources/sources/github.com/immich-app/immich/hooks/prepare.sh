#!/bin/bash
# Generates Immich's database password once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/immich"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
DB_PASSWORD=${pw}
POSTGRES_PASSWORD=${pw}
EOT
)
    echo "[panelalpha] immich: generated the database password into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
