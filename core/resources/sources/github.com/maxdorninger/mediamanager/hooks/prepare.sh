#!/bin/bash
# Generates auth.token_secret once in ~/.panelalpha (survives redeploys;
# ~/project does not), delivered to the app through env_file.
set -e
STORE="${HOME}/.panelalpha/mediamanager"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secret.env" ]; then
    (umask 077; printf 'MEDIAMANAGER_AUTH__TOKEN_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
    echo "[panelalpha] mediamanager: generated the token secret into ${STORE}/secret.env"
fi
chmod 600 "${STORE}/secret.env"
