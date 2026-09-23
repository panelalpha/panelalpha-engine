#!/bin/bash
# Runs in the account shell after the clone and after overrides/ is in place,
# before `docker compose up`. Two jobs the compose file cannot do itself: put
# this account's secrets somewhere the next deploy will not delete, and make
# sure the env files the compose references exist.
set -e
cd ~/project

say() { echo "[habitat] $*" >&2; }

# ~/.panelalpha/habitat/ survives; ~/project is emptied on every deploy
# (engine#173), so a secret written there would be regenerated on every rebuild
# -- a new APP_SECRET/ENCRYPTION_KEY logs everyone out and strands encrypted
# Settings values, and a new DB password locks the app out of the pgdata volume
# that still holds the old one. db.env holds only what postgres needs on its
# first boot; app.env holds what the app and the migration init need.
STORE_DIR="${HOME}/.panelalpha/habitat"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV_FILE="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV_FILE}" ] || [ ! -f "${DB_ENV}" ]; then
    # All values are hex/alnum: no '/', '+', '=' or quotes, so they read back
    # from an unquoted env file and embed safely inside DATABASE_URL.
    PG_PASSWORD="$(openssl rand -hex 24)"
    APP_SECRET="$(openssl rand -hex 32)"
    ENCRYPTION_KEY="$(openssl rand -hex 16)"        # 32 chars, per the README
    MERCURE_JWT="$(openssl rand -hex 32)"
    # Guaranteed upper + lower + digit so it clears User::isPasswordStrong.
    ADMIN_PASSWORD="Aa1$(openssl rand -hex 20)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV_FILE}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file does not reset the application; it strands the pgdata volume and every
# encrypted Settings value.

# Signs session cookies and CSRF/reset tokens (Symfony %env(APP_SECRET)%).
APP_SECRET=${APP_SECRET}
# AES-256-CBC passphrase for encrypted Settings (SMTP/S3 credentials). Never
# change once set, or those values become unreadable.
ENCRYPTION_KEY=${ENCRYPTION_KEY}
# Caddy's mercure directive refuses to load without a JWT key even though the
# app never publishes to it; same value for publisher and subscriber. These
# reach the container via env_file, which is why they are named in full here
# rather than interpolated in the compose file.
MERCURE_PUBLISHER_JWT_KEY=${MERCURE_JWT}
MERCURE_SUBSCRIBER_JWT_KEY=${MERCURE_JWT}
# Full DSN with the password embedded: compose cannot interpolate an env_file
# value into another value, so the whole URL is materialised here. Host is the
# sidecar service name; matches POSTGRES_PASSWORD in db.env.
DATABASE_URL=postgresql://habitat:${PG_PASSWORD}@habitat-database:5432/habitat?serverVersion=16&charset=utf8
EOF
        cat > "${NOTE}" <<EOF
Habitat on this account
=======================

Habitat is a platform for local communities: a location-based forum. Anonymous
visitors can read public posts; posting, uploading images, moderation and the
admin screens are behind a login.

ADMIN LOGIN (created by completing the one-time setup wizard)
  URL:      <this account's URL>/login
  Password: ${ADMIN_PASSWORD}

  On the very first visit the site shows a /setup wizard. It is completed once,
  right after the first deploy, using the password above; that creates the sole
  super-admin and closes /setup permanently. A redeploy does NOT reopen it (the
  'setup=complete' flag lives in the database volume) and does NOT reset the
  password.

IMAGE STORAGE
  Uploads are stored on local disk (the habitat_uploads volume). Habitat can
  also use an Amazon S3 bucket instead -- switch it from /admin/s3 -- but no
  external account is needed to run or use the app.

SELF-REGISTRATION
  Off by default. Enable it from the admin settings if you want open sign-up;
  note it then sends a verification email, so SMTP must be configured first.

EMAIL (optional)
  No SMTP is configured by default (MAILER is null), so verification, digest and
  notification emails are not delivered. Configure SMTP from /admin to enable
  them.

SECRETS
  APP_SECRET, ENCRYPTION_KEY, the Mercure JWT and the database password live in
  ${STORE_DIR} (0600). They are generated once and reused on every redeploy,
  which is what keeps logins, encrypted data and the database working across
  rebuilds. Do not delete this directory.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose file lists ~/project/.env as an env_file for compose interpolation
# defaults; make sure it exists so `docker compose up` does not abort on a
# missing file. The account's env_vars are merged into it by the platform.
touch .env
say "prepare complete"
