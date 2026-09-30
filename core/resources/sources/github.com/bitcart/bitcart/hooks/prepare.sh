#!/bin/bash
# Generates the PostgreSQL password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Upstream runs the database with trust auth instead.
set -e
STORE="${HOME}/.panelalpha/bitcart"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db.env"
fi
printf 'DB_PASSWORD=%s\n' "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
chmod 600 "${STORE}"/*.env
