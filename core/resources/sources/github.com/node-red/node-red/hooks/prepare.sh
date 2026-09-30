#!/bin/bash
# Generates the credential secret once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Never rotate NODE_RED_CREDENTIAL_SECRET: node credentials
# stored under the old one can no longer be decrypted. The admin login is the
# engine's (`credentials:`), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nodered"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

gen() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# admin.env keeps its name: accounts deployed before the engine owned the login
# hold the secret (and their old login) in it.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'NODE_RED_CREDENTIAL_SECRET=%s\n' "$(gen 40)" > "${STORE}/admin.env")
    echo "[panelalpha] node-red: generated the credential secret"
fi
chmod 600 "${STORE}/admin.env"
