#!/bin/bash
# Generates the Play secret and the database password
# once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/piga"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; {
        printf 'MARIADB_DATABASE=piga\n'
        printf 'MARIADB_USER=piga\n'
        printf 'MARIADB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'MARIADB_RANDOM_ROOT_PASSWORD=1\n'
    } > "${STORE}/db.env")
fi
if [ ! -f "${STORE}/app.env" ]; then
    # Hex only: the password goes into a JDBC URL.
    pass=$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")
    (umask 077; {
        printf 'APPLICATION_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'DATABASE_URL=jdbc:mysql://db:3306/piga?user=piga&password=%s\n' "${pass}"
    } > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env

touch .env
chmod +r panelalpha/*
