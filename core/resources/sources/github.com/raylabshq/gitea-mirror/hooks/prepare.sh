#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates the admin login once
# into ~/.panelalpha (a redeploy empties ~/project).
set -e
cd ~/project

say() { echo "[gitea-mirror] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/giteamirror"
APP_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 30 | tr -d '\n=/+' | cut -c1-24)"
    ADMIN_EMAIL="admin@gitea-mirror.local"
    (
        umask 077
        cat > "${APP_ENV}" <<EOT
# Written once on the first deploy; read only by the one-shot init service,
# which signs this user up while the database has no users.
GM_ADMIN_EMAIL=${ADMIN_EMAIL}
GM_ADMIN_NAME=admin
GM_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOT
        cat > "${NOTE}" <<EOT
Gitea Mirror on this account
============================

ADMIN LOGIN (created before the site was first reachable)
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

A password changed in the app is kept across redeploys. Sign-up is closed
(AUTH_ALLOW_SIGNUP=false). Enter your GitHub token and Gitea URL/token under
Configuration after logging in.
EOT
    )
    say "admin login written to ${NOTE}"
else
    say "reusing the admin login in ${STORE_DIR}"
fi
