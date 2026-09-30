#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha (survives redeploys;
# ~/project does not). Both the database and the app read it.
set -e

STORE="${HOME}/.panelalpha/openbudgeteer"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; printf 'POSTGRES_PASSWORD=%s\nCONNECTION_PASSWORD=%s\n' "$pw" "$pw" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"
