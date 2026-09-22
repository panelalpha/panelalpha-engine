#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs: generate the admin password
# and API key once into a place a redeploy will not wipe, and make sure the
# compose env_file exists.
set -e
cd ~/project

say() { echo "[chhoto-url] $*" >&2; }

# ~/.panelalpha survives a deploy; ~/project is emptied every time (engine#173),
# so the secrets must live here or they would be regenerated on every rebuild --
# which would lock the operator out of the links already in the SQLite volume.
STORE_DIR="${HOME}/.panelalpha/chhoto-url"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # base64 stripped of +/= so a POSIX env file reads it back verbatim. 24 raw
    # bytes -> ~32 chars, well past the app's own strength check for the API key.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    API_KEY="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once by PanelAlpha and never regenerated. Fed to the container as an
# env_file. CHHOTO_PASSWORD gates the web login and every write API; the app
# refuses nothing but leaves the API public if this is empty, so it must be set.
CHHOTO_PASSWORD=${ADMIN_PASSWORD}
CHHOTO_API_KEY=${API_KEY}
EOF
        cat > "${NOTE}" <<EOF
Chhoto URL on this account
==========================

Chhoto URL is a self-hosted URL shortener. The short links it creates are
public (that is the point); creating, listing, editing and deleting links is
behind the password below (public mode is disabled by this recipe).

ADMIN LOGIN
  URL:      <this account's URL>/
  Password: ${ADMIN_PASSWORD}

API KEY (for the CLI / JSON interface, sent as the X-API-Key header)
  ${API_KEY}

  Both are generated once and reused on every redeploy; a rebuild will not
  reset them. Change the password from the app if you like -- but note the app
  reads it from this env var on start, so to change it permanently edit
  ${APP_ENV} and redeploy.

DATA
  Links live in /data/urls.sqlite on a named Docker volume, which survives
  redeploy and storage reclaim. The secrets above are kept in ${STORE_DIR}
  (0600). Do not delete this directory.
EOF
    )
    say "password and API key written to ${APP_ENV}; notes in ${NOTE}"
else
    say "reusing the secrets in ${APP_ENV}"
fi

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even before the platform writes it, so `docker compose up` does not
# abort on a missing file. The account's env_vars are merged in and win.
touch .env
say "prepare complete"
