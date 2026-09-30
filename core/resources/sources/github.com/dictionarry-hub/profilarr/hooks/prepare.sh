#!/bin/bash
# Generates the API key once, in ~/.panelalpha (survives redeploys; ~/project
# does not). The login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/profilarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Plaintext X-Api-Key with full access (Profilarr requires >= 32 chars).
if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'PROFILARR_API_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
