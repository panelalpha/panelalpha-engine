#!/bin/bash
# Generates the token secrets, the MFA encryption key and the MariaDB password
# once into ~/.panelalpha/sync-in; the database volume keeps the first password.
set -e
DIR="${HOME}/.panelalpha/sync-in"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077
      printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_DATABASE=sync_in\n' "${pw}" > "${DIR}/db.env"
      printf '%s\n' \
        "SYNCIN_MYSQL_URL=mysql://root:${pw}@mariadb:3306/sync_in" \
        "SYNCIN_AUTH_ENCRYPTIONKEY=$(openssl rand -hex 32)" \
        "SYNCIN_AUTH_TOKEN_ACCESS_SECRET=$(openssl rand -hex 32)" \
        "SYNCIN_AUTH_TOKEN_REFRESH_SECRET=$(openssl rand -hex 32)" > "${DIR}/app.env" )
    echo "[sync-in] generated token secrets, encryption key and database password -> ${DIR}" >&2
fi
chmod 600 "${DIR}"/*.env
