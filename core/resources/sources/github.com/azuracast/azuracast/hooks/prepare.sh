#!/bin/bash
# Generates the password of AzuraCast's bundled MariaDB once into ~/.panelalpha
# (a redeploy empties ~/project; the database volume keeps the first one).
set -e

STORE_DIR="${HOME}/.panelalpha/azuracast"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    (
        umask 077
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy; the database volume is initialised with it.
MYSQL_PASSWORD=$(openssl rand -hex 24)
MYSQL_RANDOM_ROOT_PASSWORD=yes
ENV
    )
    echo "[azuracast] database password written to ${APP_ENV}" >&2
else
    echo "[azuracast] reusing ${APP_ENV}" >&2
fi
