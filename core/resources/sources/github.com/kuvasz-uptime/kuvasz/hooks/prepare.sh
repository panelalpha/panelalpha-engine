#!/bin/bash
# Generate the database password once; ~/project is wiped on every deploy.
set -e
STORE="${HOME}/.panelalpha/kuvasz"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "$pw" > "${STORE}/db.env"
     printf 'DATABASE_PASSWORD=%s\n' "$pw" > "${STORE}/app.env")
fi
touch ~/project/.env
