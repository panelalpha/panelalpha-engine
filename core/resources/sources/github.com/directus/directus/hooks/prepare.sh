#!/bin/bash
# Generates SECRET (token signing key) once, in ~/.panelalpha (survives
# redeploys; ~/project does not). Without it Directus picks a random one per start.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/directus"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
