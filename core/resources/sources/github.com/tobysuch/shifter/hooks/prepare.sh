#!/bin/bash
# SECRET_KEY, created once in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/shifter"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 48)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
