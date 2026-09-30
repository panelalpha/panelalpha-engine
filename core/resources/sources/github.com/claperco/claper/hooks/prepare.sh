#!/bin/bash
# Generates this account's secrets once into ~/.panelalpha/claper (survives a
# redeploy; ~/project does not) and makes sure the .env the compose reads exists.
# The admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[claper] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/claper"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -base64 36 | tr -d '\n=/+:' | cut -c1-32)"
    SECRET_KEY_BASE="$(openssl rand -base64 96 | tr -d '\n=/+' | cut -c1-64)"

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
ENV
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi
# An older deploy kept the admin password here too; the engine adopted it.
sed -i '/^CLAPER_ADMIN_PASSWORD=\|^# Replaces the seeded default admin/d' "${APP_ENV}"

touch .env
