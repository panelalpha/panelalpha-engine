#!/bin/bash
# APP_KEY, created once in ~/.panelalpha: ~/project is emptied on every deploy,
# and a new key would make every stored media-server credential unreadable.
set -e
STORE="${HOME}/.panelalpha/cliparr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'APP_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
