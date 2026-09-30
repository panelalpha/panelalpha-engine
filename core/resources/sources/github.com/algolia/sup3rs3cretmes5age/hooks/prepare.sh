#!/bin/bash
# Generates the Vault root token once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Upstream hardcodes "supersecret".
set -e
cd ~/project

STORE="${HOME}/.panelalpha/sup3rs3cretmes5age"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/vault.env" ]; then
    t=$(openssl rand -hex 24)
    (umask 077; printf 'VAULT_DEV_ROOT_TOKEN_ID=%s\nVAULT_TOKEN=%s\n' "$t" "$t" > "${STORE}/vault.env")
fi
chmod 600 "${STORE}/vault.env"

touch .env
