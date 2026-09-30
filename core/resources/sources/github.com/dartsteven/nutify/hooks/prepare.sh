#!/bin/bash
# SECRET_KEY generated once into ~/.panelalpha: it encrypts stored provider
# credentials, and ~/project is wiped on every deploy.
set -e
STORE="${HOME}/.panelalpha/nutify"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
