#!/bin/bash
# Download-token secret and password salt, generated once into ~/.panelalpha
# (~/project is emptied on every deploy; new values would break existing shares).
set -e
STORE="${HOME}/.panelalpha/015"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    (umask 077; printf 'SHARE_DOWNLOAD_SECRET=%s\nSHARE_PASSWORD_SALT=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 16)" > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
