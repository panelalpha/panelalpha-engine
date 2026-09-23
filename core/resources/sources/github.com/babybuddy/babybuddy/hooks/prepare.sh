#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets where the next deploy will not delete them,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[babybuddy] $*" >&2; }

# ~/.panelalpha/babybuddy survives a redeploy; ~/project is emptied every deploy
# (engine#173). A secret written under ~/project would be regenerated on every
# rebuild -- a new SECRET_KEY logs everyone out, and the admin password would no
# longer match what the rotate-admin step re-asserts against the SQLite volume.
STORE_DIR="${HOME}/.panelalpha/babybuddy"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # Django signing key: any characters are valid, but strip =/+ so it reads
    # back cleanly from an unquoted env file. ~64 chars, well over Django's need.
    SECRET_KEY="$(openssl rand -base64 64 | tr -d '\n=/+' | cut -c1-64)"
    # Admin password: no ':' or '/','+','=' so it reads back cleanly unquoted.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+:' | cut -c1-24)"

    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy. Deleting this
# forces a new SECRET_KEY (logs everyone out) and re-asserts the admin password
# on the next deploy; the children and entries in the 'config' volume are not
# touched. PUID/PGID are the account's own ids so the container keeps /config
# owned by files the account (and SFTP) can read.
PUID=$(id -u)
PGID=$(id -g)

# Django signing key; required with DEBUG off. Reused so sessions survive a
# redeploy. Overrides the image's own /config/.secretkey.
SECRET_KEY=${SECRET_KEY}

# Read by the rotate-admin one-shot to replace the shipped admin/admin default.
BB_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Baby Buddy on this account
==========================

Baby Buddy tracks a baby's feedings, sleep, diaper changes and more. Every page
is behind a login; there is no open sign-up, so caregivers are created by the
admin from Settings -> Users inside the app.

ADMIN LOGIN (the shipped admin/admin default is rotated to this on first deploy)
  URL:      <this account's URL>/login/
  Username: admin
  Password: ${ADMIN_PASSWORD}

  Change the password from the app after first login if you like; a redeploy
  will not reset it (rotate-admin only re-asserts it when it differs).

SECRETS
  SECRET_KEY and the admin password live in ${STORE_DIR} (0600) and are reused
  on every redeploy, which is what keeps logins working across rebuilds. The
  SQLite database (users, children, entries) lives on the Docker named volume
  'config' and also survives a redeploy. Do not delete either.

OPTIONAL
  Email is not configured, so notification mails are not sent. Postgres/MySQL
  are supported upstream via DATABASE_URL / DB_* but this account runs on SQLite.
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
