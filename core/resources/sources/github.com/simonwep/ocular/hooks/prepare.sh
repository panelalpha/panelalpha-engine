#!/bin/sh
# Generate the JWT signing secret once; ~/project is wiped on every deploy.
set -e
STORE="${HOME}/.panelalpha/ocular"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/genesis.env" ]; then
    (umask 077; printf 'GENESIS_JWT_SECRET=%s\n' "$(openssl rand -hex 48)" > "${STORE}/genesis.env")
    echo "[panelalpha] ocular: generated the JWT secret into ${STORE}/genesis.env"
fi
chmod 600 "${STORE}/genesis.env"
