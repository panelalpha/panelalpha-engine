#!/bin/bash
# Generates the admin password and the credential secret once, in ~/.panelalpha
# (survives redeploys; ~/project does not). Never rotate NODE_RED_CREDENTIAL_SECRET:
# node credentials stored under the old one can no longer be decrypted.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nodered"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

gen() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'NODE_RED_ADMIN_USER=admin\nNODE_RED_ADMIN_PASSWORD=%s\nNODE_RED_CREDENTIAL_SECRET=%s\n' \
        "$(gen 24)" "$(gen 40)" > "${STORE}/admin.env")
    echo "[panelalpha] node-red: generated the admin password and credential secret"
fi
chmod 600 "${STORE}/admin.env"
