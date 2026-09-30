#!/bin/bash
# Generates this account's secrets once into ~/.panelalpha/claper (survives a
# redeploy; ~/project does not) and makes sure the .env the compose reads exists.
set -e
cd ~/project

say() { echo "[claper] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/claper"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -base64 36 | tr -d '\n=/+:' | cut -c1-32)"
    SECRET_KEY_BASE="$(openssl rand -base64 96 | tr -d '\n=/+' | cut -c1-64)"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+:' | cut -c1-24)"

    (
        umask 077
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy. A new
# SECRET_KEY_BASE logs everyone out; a new DB password no longer matches the
# existing Postgres volume.
POSTGRES_USER=claper
POSTGRES_PASSWORD=${DB_PASSWORD}
POSTGRES_DB=claper
DATABASE_URL=postgres://claper:${DB_PASSWORD}@db:5432/claper
SECRET_KEY_BASE=${SECRET_KEY_BASE}
# Replaces the seeded default admin password (admin@claper.co / claper).
CLAPER_ADMIN_PASSWORD=${ADMIN_PASSWORD}
ENV
        cat > "${NOTE}" <<NOTE
Claper on this account
======================

ADMIN LOGIN
  URL:      <this account's URL>/users/log_in
  Email:    admin@claper.co
  Password: ${ADMIN_PASSWORD}

Presenter self-registration is closed (ENABLE_ACCOUNT_CREATION=false); create
presenter accounts from /admin/users. Attendees join events by code without an
account, which is how Claper is meant to be used.

Changing the password in the app is kept across redeploys. Secrets live in
${STORE_DIR} (0600); Postgres data and uploaded presentations live on named
volumes. Do not delete either.
NOTE
    )
    say "secrets written to ${STORE_DIR}; admin login in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

touch .env
