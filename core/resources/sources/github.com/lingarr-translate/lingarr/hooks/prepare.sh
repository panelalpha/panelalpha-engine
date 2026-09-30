#!/bin/bash
# Generates the admin credentials once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The seed service creates the admin from them.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/lingarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    # Alphanumeric only: the seed puts it into JSON without escaping.
    pw="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (umask 077; printf 'LINGARR_ADMIN_USER=admin\nLINGARR_ADMIN_PASSWORD=%s\n' "$pw" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

chmod +r panelalpha/lingarr-seed.sh panelalpha/lingarr-proxy.conf
