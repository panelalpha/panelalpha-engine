#!/bin/bash
# Generates the API key once, in ~/.panelalpha (survives redeploys; ~/project
# does not). The admin password is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env before this hook runs.
# The init service seeds both into Jackett.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/jackett"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/jackett.env" ]; then
    (umask 077; printf 'JACKETT_API_KEY=%s\n' "$(openssl rand -hex 16)" > "${STORE}/jackett.env")
fi
chmod 600 "${STORE}/jackett.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/jackett-seed.sh
