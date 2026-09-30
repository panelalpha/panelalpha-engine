#!/bin/bash
# Generate AUTH_SECRET and the owner password once, where a redeploy will not
# wipe them (~/project is emptied on every deploy, engine#173).
set -e
cd ~/project

say() { echo "[papra] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/papra"
APP_ENV="${STORE_DIR}/app.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        # Signs every session; rotating it logs everyone out.
        printf 'AUTH_SECRET=%s\n' "$(openssl rand -hex 48)" > "${APP_ENV}"
        # Read only by the one-shot seed container, on the first boot.
        printf 'PAPRA_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<EOF
Papra on this account
=====================

The owner account was created on the first deploy; public registration is
closed (AUTH_IS_REGISTRATION_ENABLED=false). Invite more people from an
organization, or set AUTH_IS_REGISTRATION_ENABLED=true in the project env.

OWNER LOGIN
  URL:      <this account's URL>/
  Email:    admin@<this account's domain>
  Password: ${ADMIN_PASSWORD}

Change the password from Settings after the first login; a redeploy does not
reset it. Emails (password reset, invitations) are not sent until SMTP is
configured (EMAILS_DRY_RUN=false, EMAILS_DRIVER, SMTP_* in the project env); until then they are only logged.

DATA
  SQLite database and documents live on the papra-data volume, which survives
  redeploys. AUTH_SECRET is in ${APP_ENV}; do not delete this directory.
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# app lists .env as an env_file; make sure it exists.
touch .env
