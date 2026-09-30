#!/bin/bash
# Generates NEXTAUTH_SECRET once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e

STORE="${HOME}/.panelalpha/plex-rewind"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'NEXTAUTH_SECRET=%s\n' "$(openssl rand -base64 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
