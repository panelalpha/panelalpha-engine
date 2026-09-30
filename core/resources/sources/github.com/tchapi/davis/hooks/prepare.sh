#!/bin/bash
# Generate Davis's APP_SECRET once; ~/project is wiped on every deploy.
set -e
STORE="${HOME}/.panelalpha/davis"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'APP_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
    echo "[davis] APP_SECRET written to ${STORE}" >&2
fi
