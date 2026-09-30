#!/bin/bash
# Generates KEY_ENCRYPTION_KEY and BETTER_AUTH_SECRET once into ~/.panelalpha/openbot
# (~/project is wiped on every deploy; the key encrypts the stored credential vault).
set -e
DIR="${HOME}/.panelalpha/openbot"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    ( umask 077
      printf 'KEY_ENCRYPTION_KEY=%s\nBETTER_AUTH_SECRET=%s\n' \
        "$(openssl rand -base64 32)" "$(openssl rand -hex 32)" > "${DIR}/secrets.env" )
    echo "[openbot] generated KEY_ENCRYPTION_KEY and BETTER_AUTH_SECRET -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
