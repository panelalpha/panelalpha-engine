#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the env files the compose references exist.
set -e
cd ~/project

say() { echo "[bitpoll] $*" >&2; }

# ~/.panelalpha/bitpoll/ survives the clone; ~/project is emptied on every
# deploy, so a secret written there would be regenerated on every
# rebuild -- a new SECRET_KEY logs everyone out, a new FIELD_ENCRYPTION_KEY
# strands every encrypted field, and a new DB password locks the app out of the
# pgdata volume that still holds the old one. db.env holds only what the
# postgres container needs; app.env holds what the app and init need. The admin
# login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha/bitpoll"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': read back by a POSIX shell from an unquoted env file.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # hex avoids anything an env file could misread.
    SECRET_KEY="$(openssl rand -hex 32)"
    # Fernet key: 32 random bytes, url-safe base64 (what generate_encryption_key
    # would emit). The trailing '=' is fine in an env file value.
    FIELD_ENCRYPTION_KEY="$(openssl rand -base64 32 | tr '+/' '-_')"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file does not reset the application; it strands the pgdata volume and the
# encrypted fields.

# Required with DEBUG off; signs session cookies and CSRF/reset tokens.
SECRET_KEY=${SECRET_KEY}
# Fernet key for django-encrypted-model-fields.
FIELD_ENCRYPTION_KEY=${FIELD_ENCRYPTION_KEY}
# Same value as POSTGRES_PASSWORD in db.env; the app builds its DB config from
# the discrete POSTGRES_* vars.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose file lists ~/project/.env as an env_file; make sure it exists even
# when the platform has not written it yet, so `docker compose up` does not
# abort on a missing file. The account's env_vars are merged into it by the
# platform and win over anything here.
touch .env
say "prepare complete"
