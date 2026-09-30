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
# rebuild -- a new SECRET_KEY logs everyone out. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha/babybuddy"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # Django signing key: any characters are valid, but strip =/+ so it reads
    # back cleanly from an unquoted env file. ~64 chars, well over Django's need.
    SECRET_KEY="$(openssl rand -base64 64 | tr -d '\n=/+' | cut -c1-64)"

    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy. Deleting this
# forces a new SECRET_KEY (logs everyone out); the users, children and entries
# in the 'config' volume are not touched. PUID/PGID are the account's own ids
# so the container keeps /config owned by files the account (and SFTP) can read.
PUID=$(id -u)
PGID=$(id -g)

# Django signing key; required with DEBUG off. Reused so sessions survive a
# redeploy. Overrides the image's own /config/.secretkey.
SECRET_KEY=${SECRET_KEY}
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
