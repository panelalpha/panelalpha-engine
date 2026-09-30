#!/bin/bash
# Generates the login password and the API key once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/profilarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; the app keeps its own bcrypt hash.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'PROFILARR_ADMIN_USER=admin\nPROFILARR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
# Plaintext X-Api-Key with full access (Profilarr requires >= 32 chars).
if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'PROFILARR_API_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/admin.env" "${STORE}/app.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
