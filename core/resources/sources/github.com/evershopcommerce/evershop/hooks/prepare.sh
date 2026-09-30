#!/bin/bash
# Generate the database password, session/JWT secrets and the admin password
# once, where a redeploy will not wipe them (~/project is emptied every deploy).
set -e
cd ~/project

say() { echo "[evershop] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/evershop"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ -f "${APP_ENV}" ] && [ -f "${DB_ENV}" ] && [ -f "${ADMIN_ENV}" ]; then
    say "reusing the secrets in ${STORE_DIR}"
    exit 0
fi

PG_PASSWORD="$(rnd 24)"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="$(rnd 18)"
(
    umask 077
    printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
    cat > "${APP_ENV}" <<ENV_EOF
# Written once; the database volume holds this password and live sessions and
# tokens are signed with these secrets, so do not regenerate.
DB_PASSWORD=${PG_PASSWORD}
# Session cookie secret; EverShop falls back to "keyboard cat" without it.
NODE_CONFIG={"system":{"session":{"cookieSecret":"$(rnd 48)"}}}
JWT_ADMIN_SECRET=$(rnd 48)
JWT_ADMIN_REFRESH_SECRET=$(rnd 48)
JWT_CUSTOMER_SECRET=$(rnd 48)
JWT_CUSTOMER_REFRESH_SECRET=$(rnd 48)
ORDER_TRACKING_TOKEN_SECRET=$(rnd 48)
ENV_EOF
    printf 'ADMIN_NAME=Administrator\nADMIN_EMAIL=%s\nADMIN_PASSWORD=%s\n' \
        "${ADMIN_EMAIL}" "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
    cat > "${NOTE}" <<NOTE_EOF
EverShop on this account
========================

The store administrator was created on the first deploy with EverShop's own
user:create command, before the store was reachable. No demo account exists.

ADMIN LOGIN
  URL:      <this account's URL>/admin
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

Change the email and password from the admin's account menu. A redeploy does
not reset anything: the catalogue, orders and customers live on the
postgres-data volume and uploaded images on the media volume.
NOTE_EOF
)
say "secrets and admin credentials written to ${STORE_DIR}"
