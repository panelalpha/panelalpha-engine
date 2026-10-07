#!/bin/bash
# Runs after the clone and after overrides/ is in place, before `docker compose
# up`. Mint APP_KEY once, keep it where a redeploy cannot reach, and
# materialise the ~/project/.env that docker compose interpolates ${VAR} from.
# The admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env. ~/project is wiped on every deploy; ~/.panelalpha is
# not.
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/2fauth"
SECRETS_FILE="${STORE_DIR}/secrets.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

# First deploy only. Later deploys reuse the same value so the persisted TOTP
# secrets stay decryptable (they are encrypted with APP_KEY).
if [ ! -f "${SECRETS_FILE}" ]; then
    # base64:<32 raw bytes> is the only shape Laravel's AES-256 cipher accepts;
    # a wrong-length key is a 500 on every page that touches a session or an
    # encrypted column. Rotating it makes every stored 2FA account unrecoverable.
    APP_KEY="base64:$(openssl rand -base64 32)"
    # The seeded admin's address (`credentials:`); mail defaults to the log
    # driver, so a real mailbox is not required.
    ADMIN_EMAIL="admin@2fauth.local"

    umask 077
    cat > "${SECRETS_FILE}" <<EOF
APP_KEY=${APP_KEY}
SITE_OWNER=${ADMIN_EMAIL}
EOF
    chmod 600 "${SECRETS_FILE}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env

# Pre-pull the pinned image so the init and app services start fast (best effort).
docker pull 2fauth/2fauth:8.0.2 >/dev/null 2>&1 || true
