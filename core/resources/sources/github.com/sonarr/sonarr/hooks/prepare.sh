#!/bin/bash
# Generates the login user's password once, in ~/.panelalpha (survives
# redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/sonarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; the app keeps its own hash in sonarr.db.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'SONARR_ADMIN_USER=admin\nSONARR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
