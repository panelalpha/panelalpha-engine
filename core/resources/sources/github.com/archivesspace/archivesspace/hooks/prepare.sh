#!/bin/bash
# Generates the MySQL passwords once into ~/.panelalpha (a redeploy empties
# ~/project, and the password is baked into the db-data volume).
set -e

STORE_DIR="${HOME}/.panelalpha/archivesspace"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    (
        umask 077
        cat > "${DB_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy.
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
MYSQL_PASSWORD=${DB_PASSWORD}
ENV
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy.
APPCONFIG_DB_URL=jdbc:mysql://db:3306/archivesspace?useUnicode=true&characterEncoding=UTF-8&user=as&password=${DB_PASSWORD}&useSSL=false&allowPublicKeyRetrieval=true
ENV
    )
    echo "[archivesspace] database passwords written to ${STORE_DIR}" >&2
else
    echo "[archivesspace] reusing the database passwords in ${STORE_DIR}" >&2
fi
