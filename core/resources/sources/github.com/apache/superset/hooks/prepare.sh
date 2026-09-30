#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and the secret key encrypts
# stored database credentials.
set -e
STORE="${HOME}/.panelalpha/superset"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/superset.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; printf 'SUPERSET_SECRET_KEY=%s\nDATABASE_PASSWORD=%s\nPOSTGRES_PASSWORD=%s\n' \
        "$(openssl rand -base64 42 | tr -d '\n')" "$db" "$db" > "${STORE}/superset.env")
fi
chmod 600 "${STORE}/superset.env"
