#!/bin/bash
# Secrets generated once into ~/.panelalpha (~/project is wiped on every deploy):
# the Postgres password is bound to its volume, the keys sign sessions and encrypt tokens.
set -e
STORE="${HOME}/.panelalpha/octobox"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ] || [ ! -s "${STORE}/db.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "$db" > "${STORE}/db.env"
     {
        printf 'OCTOBOX_DATABASE_PASSWORD=%s\n' "$db"
        printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)"
        printf 'OCTOBOX_ATTRIBUTE_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 16)"
     } > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
