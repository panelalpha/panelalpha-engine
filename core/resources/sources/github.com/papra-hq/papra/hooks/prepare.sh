#!/bin/bash
# Generate AUTH_SECRET once, where a redeploy will not wipe it (~/project is
# emptied on every deploy, engine#173). The owner's login is the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[papra] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/papra"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    (
        umask 077
        # Signs every session; rotating it logs everyone out.
        printf 'AUTH_SECRET=%s\n' "$(openssl rand -hex 48)" > "${APP_ENV}"
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# app lists .env as an env_file; make sure it exists.
touch .env
