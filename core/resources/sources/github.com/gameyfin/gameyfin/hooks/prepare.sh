#!/bin/bash
# Generates APP_KEY (base64, 32 bytes) once into ~/.panelalpha: it encrypts
# stored secrets, so it must survive redeploys (~/project does not).
set -e

STORE="${HOME}/.panelalpha/gameyfin"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'APP_KEY=%s\n' "$(openssl rand -base64 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
