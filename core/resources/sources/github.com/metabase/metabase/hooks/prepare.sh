#!/bin/bash
# Generate the database password, the encryption key and the admin password
# once, where a redeploy will not wipe them (~/project is emptied every deploy).
set -e
cd ~/project

say() { echo "[metabase] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/metabase"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ] || [ ! -f "${ADMIN_ENV}" ]; then
    # No '/', '+' or '=': these are read from unquoted env files.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    ENCRYPTION_KEY="$(openssl rand -hex 32)"
    # Metabase's default password policy wants a digit; retry until one appears.
    ADMIN_PASSWORD=""
    until [[ "${ADMIN_PASSWORD}" =~ [0-9] && "${ADMIN_PASSWORD}" =~ [A-Za-z] ]]; do
        ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    done
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        cat > "${APP_ENV}" <<EOF
# Written once by PanelAlpha. Never regenerate: the password is baked into the
# pgdata volume and the key encrypts connection details stored in it.
MB_DB_PASS=${PG_PASSWORD}
MB_ENCRYPTION_SECRET_KEY=${ENCRYPTION_KEY}
EOF
        printf 'MB_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<EOF
Metabase on this account
========================

The first-run setup wizard is closed: on the first deploy a one-shot service
booted Metabase on loopback only, completed /api/setup with the admin below,
and only then was the public app started.

ADMIN LOGIN
  URL:      <this account's URL>/auth/login
  Email:    admin@<this account's domain>
            (or MB_ADMIN_EMAIL, if it was set in the project env before the
            first deploy)
  Password: ${ADMIN_PASSWORD}

Change the password under Account settings after the first login; a
redeploy does not reset it.

DATA
  Metabase's own application database is PostgreSQL (pgdata volume), not the
  embedded H2 file. MB_ENCRYPTION_SECRET_KEY in app.env encrypts the database
  connection details stored there: keep this directory with any backup.
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

touch .env
