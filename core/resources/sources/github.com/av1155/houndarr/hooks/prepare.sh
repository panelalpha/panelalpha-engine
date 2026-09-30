#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/houndarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; the app keeps its own password hash.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'HOUNDARR_ADMIN_USER=admin\nHOUNDARR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

chmod +r panelalpha-seed.sh
