#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Without it Gotify would seed admin/admin.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/gotify"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'GOTIFY_DEFAULTUSER_NAME=admin\nGOTIFY_DEFAULTUSER_PASS=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
