#!/bin/bash
# Generate PROJECT_SECRET once into ~/.panelalpha/portabase (survives redeploys; ~/project does not).
set -e
STORE_DIR="${HOME}/.panelalpha/portabase"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/app.env" ]; then
    ( umask 077; printf 'PROJECT_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE_DIR}/app.env" )
    echo "[portabase] secret written to ${STORE_DIR}" >&2
fi
