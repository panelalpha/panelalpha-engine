#!/bin/bash
# Generate every secret once, persist it where a rebuild cannot reach, and
# materialise the ~/project/.env that docker compose interpolates ${VAR} from.
# ~/project is wiped on every redeploy (engine#173); ~/.panelalpha is not.
set -e

SECRETS_DIR="${HOME}/.panelalpha/mixpost"
SECRETS_FILE="${SECRETS_DIR}/secrets.env"
ADMIN_FILE="${SECRETS_DIR}/admin.txt"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${HOME}/.panelalpha" "${SECRETS_DIR}" 2>/dev/null || true

# First deploy: mint the secrets. Later deploys reuse them, so the database
# password keeps matching the persisted MySQL volume and logins survive.
if [ ! -f "${SECRETS_FILE}" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    DB_PASSWORD="$(openssl rand -hex 24)"
    MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
    REDIS_PASSWORD="$(openssl rand -hex 24)"
    # Seed the admin ON the default e-mail so the image's start.sh sees it
    # already present and never creates admin@example.com / changeme.
    ADMIN_EMAIL="admin@example.com"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"

    umask 077
    cat > "${SECRETS_FILE}" <<EOF
APP_KEY=${APP_KEY}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
REDIS_PASSWORD=${REDIS_PASSWORD}
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    chmod 600 "${SECRETS_FILE}"

    cat > "${ADMIN_FILE}" <<EOF
Mixpost administrator (created by the PanelAlpha deploy)
Login page: <your site>/mixpost/login
Email:    ${ADMIN_EMAIL}
Password: ${ADMIN_PASSWORD}
EOF
    chmod 600 "${ADMIN_FILE}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env
