#!/bin/bash
# Generate the admin password and the gallery encryption key/salt once, persist
# them where a rebuild cannot reach, and materialise the ~/project/.env that
# docker compose interpolates ${VAR} from. ~/project is wiped on every redeploy
# (engine#173); ~/.panelalpha is not.
#
# The image ships admin@example.com / "admin" and an encryption key/salt of the
# literal "ChangeMe"; both are replaced here. Reused on later deploys so the key
# keeps matching data on the persisted config volume and the password keeps
# matching the database that survived the redeploy.
set -e
cd ~/project

SECRETS_DIR="${HOME}/.panelalpha/memtly"
SECRETS_FILE="${SECRETS_DIR}/secrets.env"
CREDS_FILE="${SECRETS_DIR}/credentials"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${HOME}/.panelalpha" "${SECRETS_DIR}" 2>/dev/null || true

gen() { LC_ALL=C tr -dc 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789' < /dev/urandom | head -c "$1"; }

if [ ! -f "${SECRETS_FILE}" ]; then
    umask 077
    ADMIN_PW="$(gen 24)"
    cat > "${SECRETS_FILE}" <<EOF
MEMTLY_ADMIN_PASSWORD=${ADMIN_PW}
MEMTLY_ENCRYPTION_KEY=$(openssl rand -hex 32)
MEMTLY_ENCRYPTION_SALT=$(openssl rand -hex 16)
EOF
    chmod 600 "${SECRETS_FILE}"

    cat > "${CREDS_FILE}" <<EOF
# Written by PanelAlpha on the first deploy. The Memtly admin account for this
# installation -- sign in at https://<your-domain>/ .
#   Username: admin   (email admin@example.com)
#   Password: ${ADMIN_PW}
# Change it under the account/admin settings; this file then stops mattering.
# Self-registration is left off and needs SMTP configured in the admin UI to
# work at all, so this admin is the only account until you set that up.
EOF
    chmod 600 "${CREDS_FILE}"
    echo "[panelalpha] memtly: generated admin password + encryption key in ${SECRETS_DIR}"
fi

# docker compose reads .env from the project directory for ${VAR} interpolation.
cp "${SECRETS_FILE}" .env
chmod 600 .env
