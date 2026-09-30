#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates SECRET_KEY_BASE and
# the admin login once into ~/.panelalpha (a redeploy empties ~/project).
set -e
cd ~/project

say() { echo "[blackcandy] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/blackcandy"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    SECRET_KEY_BASE="$(openssl rand -hex 64)"
    ADMIN_PASSWORD="$(openssl rand -base64 30 | tr -d '\n=/+' | cut -c1-24)"
    ADMIN_EMAIL="admin@blackcandy.local"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy. A new
# SECRET_KEY_BASE logs everyone out.
SECRET_KEY_BASE=${SECRET_KEY_BASE}
# Replaces the seeded admin@admin.com / foobar before the port opens.
BC_ADMIN_EMAIL=${ADMIN_EMAIL}
BC_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Black Candy on this account
===========================

ADMIN LOGIN (the seeded admin@admin.com / foobar is replaced before first start)
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

Changing the email or password in the app is kept across redeploys. There is no
open sign-up; the admin creates users under Settings -> Users.

MUSIC
  Black Candy reads music from /media_data, the Docker named volume 'media'.
  Put files there (for example with docker cp into the app container) and run
  Settings -> Library -> Sync. The SQLite databases live on the 'storage'
  volume; both survive a redeploy.
EOF
    )
    say "secrets written to ${STORE_DIR}; notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose lists .env as an env_file; make sure it exists.
touch .env
