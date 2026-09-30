#!/bin/bash
# Generates the database password once, in ~/.panelalpha (survives redeploys;
# ~/project does not), and hands it to both PhotoPrism and MariaDB.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/photoprism"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    pw=$(openssl rand -hex 24)
    (umask 077
     printf 'PHOTOPRISM_DATABASE_PASSWORD=%s\n' "$pw" > "${STORE}/app.env"
     printf 'MARIADB_PASSWORD=%s\n' "$pw" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
