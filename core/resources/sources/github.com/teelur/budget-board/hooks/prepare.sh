#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha (survives redeploys;
# ~/project does not). Both the database and the server read it.
set -e

STORE="${HOME}/.panelalpha/budget-board"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"
