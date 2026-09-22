#!/bin/bash
# Generate every secret once, persist it where a rebuild cannot reach, and
# materialise the ~/project/.env that docker compose interpolates ${VAR} from.
# ~/project is wiped on every redeploy (engine#173); ~/.panelalpha is not.
set -e

SECRETS_DIR="${HOME}/.panelalpha/meetable"
SECRETS_FILE="${SECRETS_DIR}/secrets.env"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${HOME}/.panelalpha" "${SECRETS_DIR}" 2>/dev/null || true

# First deploy mints the secrets; later deploys reuse them so the database
# password keeps matching the persisted MariaDB volume.
if [ ! -f "${SECRETS_FILE}" ]; then
    umask 077
    cat > "${SECRETS_FILE}" <<EOF
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
EOF
    chmod 600 "${SECRETS_FILE}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env
