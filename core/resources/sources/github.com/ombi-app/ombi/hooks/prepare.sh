#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/ombi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; Ombi keeps its own hash in Ombi.db.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'OMBI_ADMIN_USER=admin\nOMBI_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

touch .env
chmod +r panelalpha/ombi-seed.sh
