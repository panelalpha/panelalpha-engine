#!/bin/bash
# Generates the login password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/koffan"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'APP_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
