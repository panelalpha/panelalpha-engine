#!/bin/bash
# Database passwords generated once into ~/.panelalpha (~/project is wiped on
# every deploy; a new password would lock RT out of its Postgres volume).
set -e
STORE="${HOME}/.panelalpha/rt"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    (umask 077
     {
        printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'RT_DB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
     } > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
