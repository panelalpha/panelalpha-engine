#!/bin/bash
# Generates the login password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/autobrr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; autobrr keeps its own hash in autobrr.db.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'AUTOBRR_ADMIN_USER=admin\nAUTOBRR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
