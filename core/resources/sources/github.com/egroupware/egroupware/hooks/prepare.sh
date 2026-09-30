#!/bin/bash
# Generates the MariaDB root password once into ~/.panelalpha/egroupware/
# (~/project is wiped on every deploy; a new password would lock EGroupware's
# installer out of the surviving database volume).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/egroupware"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    ROOT_PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'MARIADB_ROOT_PASSWORD=%s\nEGW_DB_ROOT_PW=%s\n' "${ROOT_PW}" "${ROOT_PW}" > "${STORE}/db.env"
    )
    echo "[egroupware] generated the database root password in ${STORE}"
fi
chmod 600 "${STORE}/db.env"
