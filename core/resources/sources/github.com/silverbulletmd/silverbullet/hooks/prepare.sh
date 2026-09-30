#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Without it the first visitor would claim the setup wizard.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/silverbullet"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'PA_SB_ADMIN_USER=admin\nPA_SB_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
