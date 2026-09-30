#!/bin/bash
# Postgres password, generated once into ~/.panelalpha (~/project is emptied on
# every deploy; the database volume keeps the first password).
set -e
STORE="${HOME}/.panelalpha/whattocook"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_DB=whattocook\nPOSTGRES_USER=whattocook\nPOSTGRES_PASSWORD=%s\n' "${pw}" > "${STORE}/db.env"
     printf 'SPRING_DATASOURCE_PASSWORD=%s\n' "${pw}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
