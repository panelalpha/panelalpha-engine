#!/bin/bash
# Generate Django's SECRET once into ~/.panelalpha/floppy (survives redeploys; ~/project does not).
set -e
STORE_DIR="${HOME}/.panelalpha/floppy"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ]; then
    ( umask 077; printf 'SECRET=%s\n' "$(openssl rand -hex 40)" > "${STORE_DIR}/app.env" )
    echo "[floppy] secret written to ${STORE_DIR}" >&2
fi
