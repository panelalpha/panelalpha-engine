#!/bin/bash
# Generate APP_SECRET once, outside ~/project (wiped every deploy): it
# encrypts the stored repository passwords.
set -e
DIR="${HOME}/.panelalpha/zerobyte"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    ( umask 077; printf 'APP_SECRET=%s\n' "$(openssl rand -hex 32)" > "${DIR}/app.env" )
    echo "[zerobyte] generated APP_SECRET -> ${DIR}/app.env" >&2
fi
