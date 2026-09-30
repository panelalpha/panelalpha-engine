#!/bin/bash
# Secrets generated once into ~/.panelalpha (~/project is wiped on every deploy):
# the Postgres password stays bound to its volume, SECRET_COOKIE_TOKEN signs sessions.
set -e
STORE="${HOME}/.panelalpha/loomio"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ] || [ ! -s "${STORE}/db.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "$db" > "${STORE}/db.env"
     {
        printf 'DATABASE_URL=postgresql://postgres:%s@db/loomio_production\n' "$db"
        printf 'SECRET_COOKIE_TOKEN=%s\n' "$(openssl rand -hex 32)"
        printf 'RAILS_INBOUND_EMAIL_PASSWORD=%s\n' "$(openssl rand -hex 32)"
     } > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
