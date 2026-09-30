#!/bin/bash
# Session and CalDAV-encryption keys, generated once into ~/.panelalpha
# (~/project is emptied on every deploy; the database needs the same keys).
set -e
STORE="${HOME}/.panelalpha/keeper"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/keeper.env" ]; then
    (umask 077
     printf 'BETTER_AUTH_SECRET=%s\nENCRYPTION_KEY=%s\n' \
        "$(openssl rand -base64 32)" "$(openssl rand -base64 32)" > "${STORE}/keeper.env")
fi
chmod 600 "${STORE}/keeper.env"
