#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[wygiwyh] $*" >&2; }

# ~/.panelalpha/wygiwyh/ survives a redeploy; ~/project is emptied every deploy
# (engine#173). A secret written under ~/project would be regenerated on every
# rebuild -- a new SECRET_KEY logs everyone out, and a new DB password locks the
# app out of the pgdata volume that still holds the old one. db.env holds only
# what the postgres container needs; app.env holds what the app needs.
STORE_DIR="${HOME}/.panelalpha/wygiwyh"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': read back cleanly from an unquoted env file.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '\n=/+')"
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# data volume, so changing it would lock the application out of its database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and never regenerated. Deleting this file
# does not reset the app; it strands the postgres data volume and logs everyone
# out. SQL_PASSWORD must equal POSTGRES_PASSWORD in db.env.

# Django signing key; required with DEBUG off.
SECRET_KEY=${SECRET_KEY}
SQL_PASSWORD=${PG_PASSWORD}

# Admin seeded once by the image's setup_users command when missing. Login and
# every finance view are behind this account.
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
WYGIWYH on this account
=======================

WYGIWYH is a personal finance tracker. Login and every finance view are behind
an account; there is no public self-registration.

ADMIN LOGIN (seeded once, on the first deploy)
  URL:      <this account's URL>/login/
  Email:    admin@example.com
  Password: ${ADMIN_PASSWORD}

  Django admin is also at <account URL>/admin/. Change the password from the app
  after first login if you like; a redeploy will not reset it (the admin is only
  seeded when missing). Create further users from inside the app.

SECRETS
  SECRET_KEY, the database password and the admin password live in ${STORE_DIR}
  (0600) and are reused on every redeploy, which is what keeps logins and the
  database working across rebuilds. Do not delete this directory.

OPTIONAL
  OIDC login and personal API tokens are supported by the app but off by
  default here. Email is not configured, so notification mails are not sent.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env
say "prepare complete"
