#!/bin/bash
# Runs on the account after the clone, before the build.
#
# ~/project is wiped and re-cloned on every redeploy (engine#173), so every
# secret this app needs is generated once into ~/.panelalpha/airtrail -- the one
# account-owned directory that survives a rebuild -- and never regenerated, so a
# redeploy cannot invalidate a database (or an owner login) that survived it.
# The values are handed to the containers through env_file (0600), never through
# the compose file the engine writes 0644 and every other tenant can read.
set -e
cd ~/project

PA="${HOME}/.panelalpha/airtrail"
DB_ENV="${PA}/db.env"
APP_ENV="${PA}/app.env"
CRED="${PA}/credentials.txt"

say() { echo "[panelalpha] airtrail: $*"; }
gen()  { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$1"; }

mkdir -p "${PA}"
chmod 700 "${PA}"

# First deploy only. app.env's presence is the "already initialised" flag; it
# lives in ~/.panelalpha (never in a container-chowned mount), so the check is
# reliable on every later redeploy. DB_PASSWORD is only ever alphanumeric so it
# is safe unquoted inside the postgres:// DB_URL.
if [ ! -f "${APP_ENV}" ]; then
    DB_PASSWORD=$(gen 32)
    OWNER_PASSWORD=$(gen 24)
    if [ ${#DB_PASSWORD} -ne 32 ] || [ ${#OWNER_PASSWORD} -ne 24 ]; then
        say "could not generate secrets from /dev/urandom" >&2
        exit 1
    fi

    umask 077

    # postgres:16-alpine reads these.
    cat > "${DB_ENV}" <<EOF
POSTGRES_USER=airtrail
POSTGRES_PASSWORD=${DB_PASSWORD}
POSTGRES_DB=airtrail
EOF
    chmod 600 "${DB_ENV}"

    # The app reads DB_URL; the wrapped entrypoint's seed step reads the
    # AIRTRAIL_OWNER_* values. ORIGIN is deliberately absent -- it is set in the
    # compose environment block, where only the engine can fill in the public
    # address.
    cat > "${APP_ENV}" <<EOF
DB_URL=postgres://airtrail:${DB_PASSWORD}@db:5432/airtrail
BODY_SIZE_LIMIT=20M
UPLOAD_LOCATION=/app/uploads
AIRTRAIL_OWNER_USERNAME=admin
AIRTRAIL_OWNER_DISPLAY_NAME=Administrator
AIRTRAIL_OWNER_PASSWORD=${OWNER_PASSWORD}
EOF
    chmod 600 "${APP_ENV}"

    cat > "${CRED}" <<EOF
AirTrail owner account (generated $(date -u +%FT%TZ))
url:      set by PanelAlpha (your project's public https domain), sign in at /login
username: admin
password: ${OWNER_PASSWORD}

This account was seeded at deploy time and AirTrail's open setup window was
closed, so nobody else could claim the installation. Change this password from
Settings after your first login. The PostgreSQL password is in db.env.
EOF
    chmod 600 "${CRED}"

    say "generated DB and owner secrets in ${PA}"
else
    say "secrets already present in ${PA}; reusing them"
fi
