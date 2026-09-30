#!/bin/bash
# Habitica crashes at boot without SESSION_SECRET_KEY (hex AES key). Generate the
# session secrets once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e

# Upstream's setup step: the client build (vite.config.mjs) and the server read
# config.json, which the repository ships only as config.json.example.
cd ~/project
[ -f config.json ] || cp config.json.example config.json

STORE="${HOME}/.panelalpha/habitica"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/secrets.env" ]; then
    (umask 077; printf 'SESSION_SECRET=%s\nSESSION_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
