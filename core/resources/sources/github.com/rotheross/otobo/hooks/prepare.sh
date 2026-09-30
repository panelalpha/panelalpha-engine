#!/bin/bash
# Generates the MariaDB root password once into ~/.panelalpha/otobo/
# (~/project is wiped on every deploy; a new password would lock OTOBO out
# of its surviving database volume). OTOBO's web installer asks for it.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/otobo"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (
        umask 077
        printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db.env"
    )
    echo "[otobo] generated the database root password in ${STORE}/db.env"
fi
chmod 600 "${STORE}/db.env"
