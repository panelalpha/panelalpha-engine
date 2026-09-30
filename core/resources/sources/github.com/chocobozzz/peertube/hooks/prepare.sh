#!/bin/bash
# Generates PeerTube's secret and DB password once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/peertube"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/secrets.env" <<EOT
POSTGRES_PASSWORD=${pw}
PEERTUBE_DB_PASSWORD=${pw}
PEERTUBE_SECRET=$(openssl rand -hex 32)
EOT
)
    echo "[panelalpha] peertube: generated secrets into ${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
