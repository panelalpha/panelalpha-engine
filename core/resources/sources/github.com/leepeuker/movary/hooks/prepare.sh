#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates the admin login once
# into ~/.panelalpha (a redeploy empties ~/project).
set -e
cd ~/project

say() { echo "[movary] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/movary"
APP_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 30 | tr -d '\n=/+' | cut -c1-24)"
    ADMIN_EMAIL="admin@movary.local"
    (
        umask 077
        cat > "${APP_ENV}" <<EOT
# Written once on the first deploy; read only by the one-shot init service,
# which creates this user while the database has no users.
MOVARY_ADMIN_EMAIL=${ADMIN_EMAIL}
MOVARY_ADMIN_NAME=admin
MOVARY_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOT
        cat > "${NOTE}" <<EOT
Movary on this account
======================

ADMIN LOGIN (created before the site was first reachable)
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

Changing the email or password in the app is kept across redeploys. There is no
public registration; the admin adds users under Settings -> Users.

TMDB: movie search needs your own TMDB API key
(https://www.themoviedb.org/settings/api), set under Settings -> Server.
EOT
    )
    say "admin login written to ${NOTE}"
else
    say "reusing the admin login in ${STORE_DIR}"
fi
