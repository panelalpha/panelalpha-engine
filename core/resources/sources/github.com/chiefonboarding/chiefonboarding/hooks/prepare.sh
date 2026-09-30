#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and SECRET_KEY signs sessions.
set -e
STORE="${HOME}/.panelalpha/chiefonboarding"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/chief.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; printf 'SECRET_KEY=%s\nPOSTGRES_PASSWORD=%s\nDATABASE_URL=postgres://chief:%s@db:5432/chiefonboarding\n' \
        "$(openssl rand -hex 32)" "$db" "$db" > "${STORE}/chief.env")
fi
chmod 600 "${STORE}/chief.env"
