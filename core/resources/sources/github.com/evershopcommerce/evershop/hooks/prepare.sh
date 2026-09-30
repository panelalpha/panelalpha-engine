#!/bin/bash
# Generate the database password and session/JWT secrets once, where a redeploy
# will not wipe them (~/project is emptied every deploy). The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[evershop] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/evershop"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ -f "${APP_ENV}" ] && [ -f "${DB_ENV}" ]; then
    say "reusing the secrets in ${STORE_DIR}"
    exit 0
fi

PG_PASSWORD="$(rnd 24)"
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
)
say "secrets written to ${STORE_DIR}"
