#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and REDASH_SECRET_KEY encrypts
# stored data source credentials.
set -e
STORE="${HOME}/.panelalpha/redash"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/redash.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; printf 'REDASH_COOKIE_SECRET=%s\nREDASH_SECRET_KEY=%s\nPOSTGRES_PASSWORD=%s\nREDASH_DATABASE_URL=postgresql://postgres:%s@postgres/postgres\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$db" "$db" > "${STORE}/redash.env")
fi
chmod 600 "${STORE}/redash.env"
