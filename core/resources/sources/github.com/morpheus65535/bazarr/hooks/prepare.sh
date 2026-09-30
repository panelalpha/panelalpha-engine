#!/bin/bash
# Generates this account's Bazarr API key once, into ~/.panelalpha (~/project is
# wiped on every deploy), and makes sure the compose's .env exists. The login is
# the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

say() { echo "[bazarr] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/bazarr"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    APIKEY="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once, reused on every redeploy. Only applied when the 'config' volume
# has no config.yaml yet (first boot); afterwards Bazarr's own config wins.
PUID=$(id -u)
PGID=$(id -g)
BAZARR_AUTH_APIKEY=${APIKEY}
EOF
    )
    say "API key written to ${STORE_DIR}"
else
    say "reusing the API key in ${STORE_DIR}"
fi

# The older recipe kept the plain login here; the engine owns it now.
rm -f "${STORE_DIR}/credentials.txt"

touch .env
say "prepare complete"
