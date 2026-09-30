#!/bin/bash
# Auth secret and database password, created once in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/manifest"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/db.env" ] || [ ! -s "${STORE}/app.env" ]; then
    DBPASS="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${DBPASS}" > "${STORE}/db.env"
     printf 'BETTER_AUTH_SECRET=%s\nDATABASE_URL=postgresql://manifest:%s@postgres:5432/manifest\n' \
         "$(openssl rand -hex 32)" "${DBPASS}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
