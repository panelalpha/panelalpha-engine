#!/bin/bash
# Generates the Django SECRET_KEY once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Production settings require it.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/posio"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secret.env" ]; then
    (umask 077; printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
fi
chmod 600 "${STORE}/secret.env"

touch .env
