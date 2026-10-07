#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[foodsoft] $*" >&2; }

# ~/.panelalpha/foodsoft/ survives the deploy; ~/project is emptied on every
# deploy, so a secret written there would be regenerated on every
# rebuild -- a new SECRET_KEY_BASE logs everyone out and voids every signed
# cookie, and a new DB password locks the app out of the mariadb volume that
# still holds the old one. db.env holds only what the mariadb container needs;
# app.env holds what the app, worker and init need. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha/foodsoft"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+', '=' or '#': read back cleanly by docker compose from an
    # unquoted env file and by database.yml's ENV lookup.
    DB_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    DB_ROOT_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # hex avoids anything an env file could misread; >=30 chars as Rails requires.
    SECRET_KEY_BASE="$(openssl rand -hex 48)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the mariadb container on its first boot to create the root and app
# roles, and by nothing else. Written once and never regenerated: the values are
# baked into the datadir volume, so changing them would lock the app out.
MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
MYSQL_PASSWORD=${DB_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file does not reset the application; it strands the mariadb volume and voids
# every signed cookie and session.

# Required in production; the app raises "You must set SECRET_KEY_BASE" without
# it. Signs session cookies and CSRF/reset tokens.
SECRET_KEY_BASE=${SECRET_KEY_BASE}
# Same value as MYSQL_PASSWORD in db.env; database.yml reads it as the app's
# MySQL password via ENV['FOODSOFT_DB_PASSWORD'].
FOODSOFT_DB_PASSWORD=${DB_PASSWORD}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose file lists ~/project/.env as an env_file; make sure it exists even
# when the platform has not written it yet, so `docker compose up` does not abort
# on a missing file. The account's env_vars are merged into it by the platform
# and win over anything here.
touch .env
say "prepare complete"
