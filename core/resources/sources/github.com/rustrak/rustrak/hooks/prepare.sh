#!/bin/bash
# Generates SESSION_SECRET_KEY once into ~/.panelalpha/rustrak (Rustrak needs
# at least 64 bytes; a new key would log every user out on each redeploy).
set -e
DIR="${HOME}/.panelalpha/rustrak"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    ( umask 077
      printf 'SESSION_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${DIR}/secrets.env" )
    echo "[rustrak] generated SESSION_SECRET_KEY -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
