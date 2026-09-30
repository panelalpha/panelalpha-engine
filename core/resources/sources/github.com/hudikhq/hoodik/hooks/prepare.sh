#!/bin/bash
# JWT_SECRET must stay stable across deploys (upstream: otherwise every session
# is invalidated on restart), so it lives in ~/.panelalpha, not ~/project.
set -e
STORE_DIR="${HOME}/.panelalpha/hoodik"
APP_ENV="${STORE_DIR}/app.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${APP_ENV}" ]; then
    (umask 077; printf 'JWT_SECRET=%s\n' "$(openssl rand -hex 32)" > "${APP_ENV}")
    echo "[hoodik] generated JWT_SECRET in ${APP_ENV}" >&2
else
    echo "[hoodik] reusing ${APP_ENV}" >&2
fi
