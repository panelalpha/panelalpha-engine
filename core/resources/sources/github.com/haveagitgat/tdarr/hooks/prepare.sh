#!/bin/bash
# Generates Tdarr's secrets once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this
# hook runs.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/tdarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read by the app: JWT signing key, and one API key shared by the server
# (seededApiKey) and its internal node (apiKey).
if [ ! -f "${STORE}/tdarr.env" ]; then
    key="tapi_$(openssl rand -hex 16)"
    (umask 077; printf 'authSecretKey=%s\nseededApiKey=%s\napiKey=%s\n' \
        "$(openssl rand -hex 32)" "${key}" "${key}" > "${STORE}/tdarr.env")
fi

chmod 600 "${STORE}/tdarr.env"

chmod +r panelalpha-seed.sh
