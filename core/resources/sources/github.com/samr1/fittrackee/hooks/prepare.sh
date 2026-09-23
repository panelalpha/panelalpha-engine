#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets where the next deploy will not delete them,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[fittrackee] $*" >&2; }

# ~/.panelalpha/fittrackee survives a redeploy; ~/project is emptied every deploy
# (engine#173). A secret written under ~/project would be regenerated on every
# rebuild -- a new APP_SECRET_KEY logs everyone out, and a new DB password would
# lock the app out of the existing Postgres volume.
STORE_DIR="${HOME}/.panelalpha/fittrackee"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # Generated once, reused forever. Strip =/+ / so the values read back cleanly
    # from an unquoted env file and out of a URL.
    DB_PASSWORD="$(openssl rand -base64 36 | tr -d '\n=/+:' | cut -c1-32)"
    APP_SECRET_KEY="$(openssl rand -base64 64 | tr -d '\n=/+' | cut -c1-64)"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+:' | cut -c1-24)"

    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy. Deleting this
# forces a new APP_SECRET_KEY (logs everyone out) and a new DB password (which
# no longer matches the existing Postgres volume). The workouts and uploads in
# the named volumes are not touched by a rebuild.

# Postgres (read by the db sidecar and embedded in DATABASE_URL below; the same
# password must appear in both, which is why they are written together here).
POSTGRES_USER=fittrackee
POSTGRES_PASSWORD=${DB_PASSWORD}
POSTGRES_DB=fittrackee
DATABASE_URL=postgresql://fittrackee:${DB_PASSWORD}@fittrackee-db:5432/fittrackee

# Flask signing key; required in production. Reused so sessions survive redeploy.
APP_SECRET_KEY=${APP_SECRET_KEY}

# Read by the seed-admin one-shot to create the owner account on first deploy.
# Email is only an identifier here (no SMTP configured).
FT_ADMIN_USER=admin
FT_ADMIN_EMAIL=admin@fittrackee.local
FT_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
FitTrackee on this account
==========================

FitTrackee imports GPX files and tracks outdoor activities per sport. The whole
app is behind a login; workouts are private by default. New users can self-
register (each sees only their own data and workouts explicitly made public);
the owner can disable self-registration from Administration -> Application.

ADMIN LOGIN (seeded on the first deploy)
  URL:      <this account's URL>/login
  Username: admin
  Password: ${ADMIN_PASSWORD}
  Role:     owner (full administration)

  Change the password from the app after first login if you like; a redeploy
  will not reset it.

SECRETS
  APP_SECRET_KEY and the Postgres password live in ${STORE_DIR} (0600) and are
  reused on every redeploy, which keeps logins working and the database readable
  across rebuilds. The Postgres data, uploads (GPX/avatars), staticmap cache and
  logs live on Docker named volumes and also survive a redeploy. Do not delete
  either.

OPTIONAL
  Email is not configured, so notification/confirmation mails are not sent
  (accounts are created active). Redis is running for API rate limits; async
  data export is not processed (no worker), which only affects the user data
  export feature.
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
