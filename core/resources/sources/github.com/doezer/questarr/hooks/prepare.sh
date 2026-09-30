#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/questarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; Questarr keeps its own bcrypt hash.
# The "Qa1" prefix satisfies the letter + digit password policy.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'QUESTARR_ADMIN_USER=admin\nQUESTARR_ADMIN_PASSWORD=Qa1%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

touch .env
chmod +r panelalpha-seed.mjs
