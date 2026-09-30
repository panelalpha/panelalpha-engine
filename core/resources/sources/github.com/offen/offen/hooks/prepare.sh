#!/bin/bash
# Generates the cookie/token signing secret once, in ~/.panelalpha (survives
# redeploys; ~/project does not). Offen wants 16 bytes, base64.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/offen"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077
     printf 'OFFEN_SECRET=%s\n' "$(openssl rand -base64 16)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
