#!/bin/bash
# Generates the admin login once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/prismarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; Prismarr keeps its own password hash.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'PRISMARR_ADMIN_EMAIL=admin@prismarr.local\nPRISMARR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

chmod +r panelalpha-seed.sh
