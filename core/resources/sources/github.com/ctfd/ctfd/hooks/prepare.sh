#!/bin/bash
# Generates the MariaDB passwords and CTFd's SECRET_KEY once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/ctfd"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    DB_PASS=$(openssl rand -hex 16)
    (umask 077
     printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' "${DB_PASS}" "$(openssl rand -hex 16)" > "${STORE}/db.env"
     printf 'DATABASE_URL=mysql+pymysql://ctfd:%s@db/ctfd\nSECRET_KEY=%s\n' "${DB_PASS}" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
