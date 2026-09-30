#!/bin/bash
# Generates Headplane's cookie secret once into ~/.panelalpha (a redeploy empties ~/project).
set -e
STORE_DIR="${HOME}/.panelalpha/headplane"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/headplane.env" ]; then
    ( umask 077; printf 'HEADPLANE_SERVER__COOKIE_SECRET=%s\n' "$(openssl rand -hex 16)" > "${STORE_DIR}/headplane.env" )
    echo "[headplane] cookie secret written to ${STORE_DIR}" >&2
else
    echo "[headplane] reusing ${STORE_DIR}/headplane.env" >&2
fi
