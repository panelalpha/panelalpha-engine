#!/bin/bash
# Generates API_TOKEN (JWT signing key) once in ~/.panelalpha (survives
# redeploys; ~/project does not). Unset, the backend uses a public default.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mymangadb"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secret.env" ]; then
    (umask 077; printf 'API_TOKEN=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
fi
chmod 600 "${STORE}/secret.env"

touch .env
