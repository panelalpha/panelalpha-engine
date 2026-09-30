#!/bin/bash
# Generate the database password and the encryption key once, where a redeploy
# will not wipe them (~/project is emptied every deploy). The admin login is the
# engine's (`credentials:`), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[metabase] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/metabase"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    # No '/', '+' or '=': these are read from unquoted env files.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    ENCRYPTION_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        cat > "${APP_ENV}" <<EOF
# Written once by PanelAlpha. Never regenerate: the password is baked into the
# pgdata volume and the key encrypts connection details stored in it.
MB_DB_PASS=${PG_PASSWORD}
MB_ENCRYPTION_SECRET_KEY=${ENCRYPTION_KEY}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

touch .env
