#!/bin/bash
# Generate every secret once, persist it where a rebuild cannot reach, and
# materialise the ~/project/.env that docker compose interpolates ${VAR} from.
# ~/project is wiped on every redeploy; ~/.panelalpha is not. The
# administrator login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
set -e

SECRETS_DIR="${HOME}/.panelalpha/mixpost"
SECRETS_FILE="${SECRETS_DIR}/secrets.env"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${HOME}/.panelalpha" "${SECRETS_DIR}" 2>/dev/null || true

# First deploy: mint the secrets. Later deploys reuse them, so the database
# password keeps matching the persisted MySQL volume and logins survive.
if [ ! -f "${SECRETS_FILE}" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    DB_PASSWORD="$(openssl rand -hex 24)"
    MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
    REDIS_PASSWORD="$(openssl rand -hex 24)"

    umask 077
    cat > "${SECRETS_FILE}" <<EOF
APP_KEY=${APP_KEY}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
REDIS_PASSWORD=${REDIS_PASSWORD}
EOF
    chmod 600 "${SECRETS_FILE}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env
