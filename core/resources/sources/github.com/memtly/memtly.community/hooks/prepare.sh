#!/bin/bash
# Generate the gallery encryption key/salt once, persist them where a rebuild
# cannot reach, and materialise the ~/project/.env that docker compose
# interpolates ${VAR} from. ~/project is wiped on every redeploy (engine#173);
# ~/.panelalpha is not.
#
# The image ships admin@example.com / "admin" and an encryption key/salt of the
# literal "ChangeMe"; both are replaced. The key is reused on later deploys so it
# keeps matching data on the persisted config volume. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

SECRETS_DIR="${HOME}/.panelalpha/memtly"
SECRETS_FILE="${SECRETS_DIR}/secrets.env"
ADMIN_ENV="${SECRETS_DIR}/admin.env"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${HOME}/.panelalpha" "${SECRETS_DIR}" 2>/dev/null || true

if [ ! -f "${SECRETS_FILE}" ]; then
    umask 077
    cat > "${SECRETS_FILE}" <<EOF
MEMTLY_ENCRYPTION_KEY=$(openssl rand -hex 32)
MEMTLY_ENCRYPTION_SALT=$(openssl rand -hex 16)
EOF
    chmod 600 "${SECRETS_FILE}"
    echo "[panelalpha] memtly: generated the encryption key in ${SECRETS_DIR}"
fi

# The image reads the admin as ACCOUNT_ADMIN_EMAIL / ACCOUNT_ADMIN_PASSWORD.
# Rewritten every deploy into a 0600 env_file, never into ~/project/.env, which
# the engine republishes at 644.
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
( umask 077; printf 'ACCOUNT_ADMIN_EMAIL=%s\nACCOUNT_ADMIN_PASSWORD=%s\n' "${MEMTLY_ADMIN_EMAIL}" "${MEMTLY_ADMIN_PASSWORD}" > "${ADMIN_ENV}" )
chmod 600 "${ADMIN_ENV}"

# docker compose reads .env from the project directory for ${VAR} interpolation.
# An older account's secrets.env still carries MEMTLY_ADMIN_PASSWORD; it stays out.
grep -v '^MEMTLY_ADMIN_PASSWORD=' "${SECRETS_FILE}" > .env
chmod 600 .env
