#!/bin/bash
# Generates the MariaDB passwords once in ~/.panelalpha (survives redeploys;
# ~/project does not). A new password against the kept volume would lock
# LibreNMS out of its database.
set -e
STORE="${HOME}/.panelalpha/librenms"
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
