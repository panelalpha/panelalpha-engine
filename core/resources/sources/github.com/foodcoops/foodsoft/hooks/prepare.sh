#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[foodsoft] $*" >&2; }

# ~/.panelalpha/foodsoft/ survives the deploy; ~/project is emptied on every
# deploy (engine#173), so a secret written there would be regenerated on every
# rebuild -- a new SECRET_KEY_BASE logs everyone out and voids every signed
# cookie, and a new DB password locks the app out of the mariadb volume that
# still holds the old one. db.env holds only what the mariadb container needs;
# app.env holds what the app, worker and init need.
STORE_DIR="${HOME}/.panelalpha/foodsoft"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+', '=' or '#': read back cleanly by docker compose from an
    # unquoted env file and by database.yml's ENV lookup.
    DB_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    DB_ROOT_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    ADMIN_PASSWORD="$(openssl rand -base64 15 | tr -d '\n=/+')"
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

# Admin seeded once by init.sh when the database is empty. Everything past the
# login is behind auth; this is the coop's first administrator account.
FOODSOFT_ADMIN_NICK=admin
FOODSOFT_ADMIN_EMAIL=admin@example.com
FOODSOFT_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Foodsoft on this account
========================

Foodsoft runs a non-profit food coop: suppliers, an article catalog, group
orders and member accounting. Everything past the login page requires an
account; the root URL redirects to the coop login. New members are added by
invitation from inside the app (there is no open public sign-up).

ADMIN LOGIN (seeded once, on the first deploy)
  URL:      <this account's URL>/    (redirects to /f/login)
  Login:    admin
  Password: ${ADMIN_PASSWORD}

  The stock Foodsoft demo login admin/secret is deliberately NOT installed.
  Change the password from the profile page after first login if you like; a
  redeploy will not reset it (init only seeds the admin on an empty database).

SECRETS
  SECRET_KEY_BASE and the database password live in ${STORE_DIR} (0600). They
  are generated once and reused on every redeploy, which is what keeps logins,
  sessions and the database working across rebuilds. Do not delete this
  directory.

EMAIL (optional)
  Foodsoft sends invitations, password resets and order notifications. No SMTP
  is configured by default, so those mails are not delivered. Set SMTP_ADDRESS
  (and the other SMTP_* variables, see config/environments/production.rb) in
  this project's environment to enable them.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose file lists ~/project/.env as an env_file; make sure it exists even
# when the platform has not written it yet, so `docker compose up` does not abort
# on a missing file. The account's env_vars are merged into it by the platform
# and win over anything here.
touch .env
say "prepare complete"
