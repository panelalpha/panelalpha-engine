#!/bin/bash
# Generates the MariaDB passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The web installer asks for DB_PASSWORD from db.env.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/teampass"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    printf 'DB_PASSWORD=%s\n' "$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
