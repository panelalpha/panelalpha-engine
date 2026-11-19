#!/bin/bash
# Generates the MySQL passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/xxl-job"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    DB_PASS=$(openssl rand -hex 16)
    (umask 077
     printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "${DB_PASS}" "$(openssl rand -hex 16)" > "${STORE}/db.env"
     printf 'SPRING_DATASOURCE_PASSWORD=%s\n' "${DB_PASS}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
