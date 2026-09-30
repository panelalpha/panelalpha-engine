#!/bin/bash
# ENCRYPTION_KEY (a Fernet key), created once in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/unifi-toolkit"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'ENCRYPTION_KEY=%s\n' "$(openssl rand 32 | base64 | tr '+/' '-_')" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
