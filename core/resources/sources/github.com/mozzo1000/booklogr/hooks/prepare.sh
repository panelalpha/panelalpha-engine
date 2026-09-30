#!/bin/bash
# Generates AUTH_SECRET_KEY once in ~/.panelalpha (survives redeploys;
# ~/project does not). Without it the API refuses to start.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/booklogr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secret.env" ]; then
    (umask 077; printf 'AUTH_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
fi
chmod 600 "${STORE}/secret.env"

touch .env
