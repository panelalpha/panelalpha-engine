#!/bin/bash
# Generates the MongoDB passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The controller admin is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/unifi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Hex only: the image seds MONGO_PASS into a mongodb:// URI.
if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; {
        printf 'MONGO_USER=unifi\n'
        printf 'MONGO_PASS=%s\n' "$(openssl rand -hex 24)"
        printf 'MONGO_DBNAME=unifi\n'
        printf 'MONGO_AUTHSOURCE=admin\n'
    } > "${STORE}/db.env")
fi
if [ ! -f "${STORE}/db-root.env" ]; then
    (umask 077; {
        printf 'MONGO_INITDB_ROOT_USERNAME=root\n'
        printf 'MONGO_INITDB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    } > "${STORE}/db-root.env")
fi
chmod 600 "${STORE}"/*.env

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/*
