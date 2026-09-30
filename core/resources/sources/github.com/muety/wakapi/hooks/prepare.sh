#!/bin/bash
# Generates the password salt once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The owner's login is the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this hook.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/wakapi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# The salt peppers every password hash: rolling it locks everyone out.
if [ ! -f "${STORE}/wakapi.env" ]; then
    (umask 077; echo "WAKAPI_PASSWORD_SALT=$(openssl rand -hex 32)" > "${STORE}/wakapi.env")
fi
chmod 600 "${STORE}/wakapi.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
