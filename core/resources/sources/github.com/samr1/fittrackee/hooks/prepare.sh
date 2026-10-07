#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets where the next deploy will not delete them,
# and make sure the .env the compose references exists. The owner's login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[fittrackee] $*" >&2; }

# ~/.panelalpha/fittrackee survives a redeploy; ~/project is emptied every deploy.
# A secret written under ~/project would be regenerated on every
# rebuild -- a new APP_SECRET_KEY logs everyone out, and a new DB password would
# lock the app out of the existing Postgres volume.
STORE_DIR="${HOME}/.panelalpha/fittrackee"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # Generated once, reused forever. Strip =/+ / so the values read back cleanly
    # from an unquoted env file and out of a URL.
    DB_PASSWORD="$(openssl rand -base64 36 | tr -d '\n=/+:' | cut -c1-32)"
    APP_SECRET_KEY="$(openssl rand -base64 64 | tr -d '\n=/+' | cut -c1-64)"

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
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env
say "prepare complete"
