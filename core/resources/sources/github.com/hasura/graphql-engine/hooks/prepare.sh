#!/bin/bash
# Generates the PostgreSQL password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e

STORE="${HOME}/.panelalpha/hasura"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    PG_PASS=$(openssl rand -hex 16)
    URL="postgres://hasura:${PG_PASS}@postgres:5432/hasura"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASS}" > "${STORE}/db.env"
     printf 'HASURA_GRAPHQL_METADATA_DATABASE_URL=%s\nPG_DATABASE_URL=%s\n' "${URL}" "${URL}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
