#!/bin/bash
# Generate LibreChat's credential-encryption key/IV and JWT secrets once into
# ~/.panelalpha/librechat (survives redeploys; ~/project does not).
set -e
STORE_DIR="${HOME}/.panelalpha/librechat"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ]; then
    ( umask 077; printf 'CREDS_KEY=%s\nCREDS_IV=%s\nJWT_SECRET=%s\nJWT_REFRESH_SECRET=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 16)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" \
        > "${STORE_DIR}/app.env" )
    echo "[librechat] secrets written to ${STORE_DIR}" >&2
fi
