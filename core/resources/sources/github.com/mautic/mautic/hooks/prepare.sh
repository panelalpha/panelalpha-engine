#!/bin/bash
# Generates the database passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not, and the MySQL volume keeps the first password).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mautic"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    pw=$(openssl rand -hex 24)
    (umask 077
     printf 'MAUTIC_DB_PASSWORD=%s\n' "$pw" > "${STORE}/app.env"
     printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "$pw" "$(openssl rand -hex 24)" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
