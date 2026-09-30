#!/bin/bash
# Seed this account's secrets once into ~/.panelalpha/yamtrack (survives redeploys;
# ~/project does not) and make sure the .env the compose file lists exists.
# The admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/yamtrack"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${STORE_DIR}/app.env" ]; then
    SECRET="$(openssl rand -hex 40)"
    (
        umask 077
        printf '# Django signing key; rotating it logs everyone out.\nSECRET=%s\n' "${SECRET}" > "${STORE_DIR}/app.env"
    )
    echo "[yamtrack] secrets written to ${STORE_DIR}" >&2
else
    echo "[yamtrack] reusing the secrets in ${STORE_DIR}" >&2
fi

touch .env
