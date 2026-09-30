#!/bin/bash
# Generates the MongoDB passwords once in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nodebb"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db-user.env" ]; then
    # Hex only: it goes into a mongodb:// URI.
    PW="$(openssl rand -hex 24)"
    (umask 077
     printf 'MONGO_NODEBB_PASSWORD=%s\n' "${PW}" > "${STORE}/db-user.env"
     printf 'mongo__uri=mongodb://nodebb:%s@mongo:27017/nodebb\n' "${PW}" > "${STORE}/db.env")
fi
if [ ! -f "${STORE}/db-root.env" ]; then
    (umask 077; printf 'MONGO_INITDB_ROOT_USERNAME=root\nMONGO_INITDB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db-root.env")
fi
chmod 600 "${STORE}"/*.env
chmod +r panelalpha/*
