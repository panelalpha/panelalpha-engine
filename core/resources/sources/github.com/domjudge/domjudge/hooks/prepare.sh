#!/bin/bash
# Generates the MariaDB passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Both services read the same file.
set -e

STORE="${HOME}/.panelalpha/domjudge"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (umask 077
     printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"
