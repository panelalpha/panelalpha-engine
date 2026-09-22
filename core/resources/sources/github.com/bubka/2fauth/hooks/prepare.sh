#!/bin/bash
# Runs after the clone and after overrides/ is in place, before `docker compose
# up`. Mint the two secrets 2FAuth needs once, keep them where a redeploy cannot
# reach, and materialise the ~/project/.env that docker compose interpolates
# ${VAR} from. ~/project is wiped on every deploy (engine#173); ~/.panelalpha is
# not.
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/2fauth"
SECRETS_FILE="${STORE_DIR}/secrets.env"
ADMIN_FILE="${STORE_DIR}/admin.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

# First deploy only. Later deploys reuse the same values so the persisted TOTP
# secrets stay decryptable (they are encrypted with APP_KEY) and the owner keeps
# the same login.
if [ ! -f "${SECRETS_FILE}" ]; then
    # base64:<32 raw bytes> is the only shape Laravel's AES-256 cipher accepts;
    # a wrong-length key is a 500 on every page that touches a session or an
    # encrypted column. Rotating it makes every stored 2FA account unrecoverable.
    APP_KEY="base64:$(openssl rand -base64 32)"
    # Fixed, self-contained admin address; the owner can change it in-app after
    # first login. Mail defaults to the log driver, so a real mailbox is not
    # required to sign in.
    ADMIN_EMAIL="admin@2fauth.local"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"

    umask 077
    cat > "${SECRETS_FILE}" <<EOF
APP_KEY=${APP_KEY}
SEED_ADMIN_EMAIL=${ADMIN_EMAIL}
SEED_ADMIN_PASSWORD=${ADMIN_PASSWORD}
SEED_ADMIN_NAME=admin
SITE_OWNER=${ADMIN_EMAIL}
EOF
    chmod 600 "${SECRETS_FILE}"

    cat > "${ADMIN_FILE}" <<EOF
2FAuth administrator (created by the PanelAlpha deploy)
Login page: <your site>/login
Email:    ${ADMIN_EMAIL}
Password: ${ADMIN_PASSWORD}

Open registration is disabled. Re-open it, or change this email/password, from
the in-app admin settings after logging in.
EOF
    chmod 600 "${ADMIN_FILE}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env

# Pre-pull the pinned image so the init and app services start fast (best effort).
docker pull 2fauth/2fauth:8.0.2 >/dev/null 2>&1 || true
