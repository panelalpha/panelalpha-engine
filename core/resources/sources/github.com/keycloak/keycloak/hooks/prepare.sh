#!/bin/bash
# Generates the PostgreSQL password once, in ~/.panelalpha (survives
# redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/keycloak"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    DB_PASS=$(openssl rand -hex 16)
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASS}" > "${STORE}/db.env"
     printf 'KC_DB_PASSWORD=%s\n' "${DB_PASS}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
