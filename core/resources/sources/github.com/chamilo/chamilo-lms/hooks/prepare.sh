#!/bin/bash
# Generates the MariaDB credentials once into ~/.panelalpha/chamilo/
# (~/project is wiped on every deploy; a new password would lock Chamilo out
# of its surviving database volume). Chamilo's web installer asks for them.
set -e

STORE="${HOME}/.panelalpha/chamilo"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (
        umask 077
        {
            printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
            printf 'MARIADB_DATABASE=chamilo\n'
            printf 'MARIADB_USER=chamilo\n'
            printf 'MARIADB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        } > "${STORE}/db.env"
    )
    echo "[chamilo] generated the database credentials in ${STORE}/db.env"
fi
chmod 600 "${STORE}/db.env"
