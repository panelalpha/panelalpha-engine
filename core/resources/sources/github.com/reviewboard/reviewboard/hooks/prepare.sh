#!/bin/bash
# Generates the PostgreSQL password once in ~/.panelalpha (survives redeploys;
# ~/project does not). Two files: the database and Review Board name it differently.
set -e
STORE="${HOME}/.panelalpha/reviewboard"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    PW="$(openssl rand -hex 24)"
    printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${STORE}/db.env"
    printf 'DATABASE_PASSWORD=%s\n' "${PW}" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
