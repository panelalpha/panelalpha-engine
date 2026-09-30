#!/bin/bash
# Generates Tdarr's secrets and the admin password once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
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

# Read only by the seed service; Tdarr keeps its own hash.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'TDARR_ADMIN_USER=admin\nTDARR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/tdarr.env" "${STORE}/admin.env"

chmod +r panelalpha-seed.sh
