#!/bin/bash
# Generates this account's Bazarr login and API key once, into ~/.panelalpha
# (~/project is wiped on every deploy), and makes sure the compose's .env exists.
set -e
cd ~/project

say() { echo "[bazarr] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/bazarr"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+:' | cut -c1-24)"
    PASSWORD_MD5="$(printf '%s' "${PASSWORD}" | md5sum | cut -d' ' -f1)"
    APIKEY="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once, reused on every redeploy. Only applied when the 'config' volume
# has no config.yaml yet (first boot); afterwards Bazarr's own config wins.
PUID=$(id -u)
PGID=$(id -g)
BAZARR_AUTH_USERNAME=admin
BAZARR_AUTH_PASSWORD_MD5=${PASSWORD_MD5}
BAZARR_AUTH_APIKEY=${APIKEY}
EOF
        cat > "${NOTE}" <<EOF
Bazarr on this account
======================

LOGIN (form authentication is enabled on first deploy)
  URL:      <this account's URL>/
  Username: admin
  Password: ${PASSWORD}

API KEY (Settings -> General; used by Sonarr/Radarr webhooks and scripts)
  ${APIKEY}

Connect Sonarr and Radarr from Settings -> Sonarr / Radarr in the UI. All
settings, the SQLite database and backups live on the Docker named volume
'config' and survive a redeploy. Changing the password or API key in the UI is
kept; the values above are only the initial ones.
EOF
    )
    say "credentials written to ${STORE_DIR}"
else
    say "reusing the credentials in ${STORE_DIR}"
fi

touch .env
say "prepare complete"
