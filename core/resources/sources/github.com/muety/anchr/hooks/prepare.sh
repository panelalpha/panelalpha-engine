#!/bin/bash
# JWT secret and MongoDB credentials, generated once into ~/.panelalpha
# (~/project is emptied on every deploy; the database keeps the first password).
set -e
STORE="${HOME}/.panelalpha/anchr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/mongo.env" ]; then
    root_pw="$(openssl rand -hex 24)"; app_pw="$(openssl rand -hex 24)"
    (umask 077
     printf 'MONGO_INITDB_ROOT_USERNAME=root\nMONGO_INITDB_ROOT_PASSWORD=%s\nMONGO_INITDB_DATABASE=anchr\nDB_USER=anchr\nDB_PASSWORD=%s\n' \
         "${root_pw}" "${app_pw}" > "${STORE}/mongo.env"
     printf 'ANCHR_SECRET=%s\nANCHR_DB_PASSWORD=%s\n' "$(openssl rand -hex 32)" "${app_pw}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/mongo.env" "${STORE}/app.env"
