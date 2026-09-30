#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates the Flask
# SECRET_KEY once into ~/.panelalpha (a redeploy empties ~/project).
set -e

STORE_DIR="${HOME}/.panelalpha/superdesk"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    (
        umask 077
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy.
SECRET_KEY=$(openssl rand -hex 32)
ENV
    )
    echo "[superdesk] secret key written to ${STORE_DIR}" >&2
else
    echo "[superdesk] reusing the secret key in ${STORE_DIR}" >&2
fi
