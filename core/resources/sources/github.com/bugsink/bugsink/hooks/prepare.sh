#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[bugsink] $*" >&2; }

# ~/.panelalpha/bugsink/ survives a redeploy; ~/project is emptied every deploy
# (engine#173). A secret written under ~/project would be regenerated on every
# rebuild -- a new SECRET_KEY logs everyone out, and a new admin password would
# not even take effect (CREATE_SUPERUSER only runs when the DB has no users, and
# the DB lives on a named volume that outlives the deploy).
STORE_DIR="${HOME}/.panelalpha/bugsink"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"
ADMIN_EMAIL="admin@example.org"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # SECRET_KEY: Django signing key, required with DEBUG off and checked by
    # `bugsink-manage check --deploy` at boot; must be long and must not carry
    # the `django-insecure-` prefix. base64 of 50 bytes is ~66 chars.
    SECRET_KEY="$(openssl rand -base64 50 | tr -d '\n')"
    # Admin password: no ':' (CREATE_SUPERUSER splits on it) and no '/', '+', '='
    # so it reads back cleanly from an unquoted env file.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+:' | cut -c1-24)"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and never regenerated. Deleting this file
# does not reset the app; it logs everyone out (new SECRET_KEY) while the admin
# and all data stay in the SQLite volume. Reused on every redeploy.

# Django signing key; required with DEBUG off.
SECRET_KEY=${SECRET_KEY}

# Seeded once by the image's prestart step, only when the database has no users
# (email:password). The dashboard and every issue view are behind this account.
CREATE_SUPERUSER=${ADMIN_EMAIL}:${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Bugsink on this account
=======================

Bugsink is a self-hosted, Sentry-compatible error tracker. The dashboard and
every issue/project view are behind a login; the Sentry ingest endpoints
(/api/<project>/envelope/) accept events with a project DSN key by design.

ADMIN LOGIN (seeded once, on the first deploy)
  URL:      <this account's URL>/
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

  Change the password from the app after first login if you like; a redeploy
  will not reset it (the admin is only seeded when the database has no users).
  Create further users from inside the app (USER_REGISTRATION=CB_ADMINS: only
  admins can add users; there is no open self-registration).

SECRETS
  SECRET_KEY and the admin credential live in ${STORE_DIR} (0600) and are reused
  on every redeploy, which is what keeps logins working across rebuilds. The
  SQLite database (users, projects, issues, events) lives on the Docker named
  volume 'data' and also survives a redeploy. Do not delete either.

OPTIONAL
  Email (alerts) is not configured, so notification mails are not sent. Postgres
  is supported upstream via DATABASE_URL but this account runs on SQLite.
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
