#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The htpasswd-seed service hashes it into storage.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/verdaccio"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'VERDACCIO_ADMIN_USER=admin\nVERDACCIO_ADMIN_PASSWORD=%s\n' \
        "$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)" > "${STORE}/admin.env")
    echo "[panelalpha] verdaccio: generated the admin password"
fi
chmod 600 "${STORE}/admin.env"
