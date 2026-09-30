#!/bin/bash
# Generates the MariaDB passwords and Laravel APP_KEY once in ~/.panelalpha
# (survives redeploys; ~/project does not). APP_KEY encrypts stored service
# credentials: the image would otherwise generate a new one in every container.
set -e
STORE="${HOME}/.panelalpha/dreamfactory"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    printf 'APP_KEY=base64:%s\nDB_PASSWORD=%s\n' "$(openssl rand -base64 32)" \
        "$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
