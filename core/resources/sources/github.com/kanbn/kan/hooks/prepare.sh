#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Its job is to put this account's secrets
# somewhere the next deploy will not delete, and to make sure the env files the
# compose references exist.
set -e
cd ~/project

say() { echo "[kan] $*" >&2; }

# ~/.panelalpha/kan/ survives the clone; ~/project is emptied on every deploy
# (engine#173), so a secret written there would be regenerated on every rebuild
# -- a new BETTER_AUTH_SECRET logs everyone out and invalidates sessions, and a
# new DB password locks the app out of the pgdata volume that still holds the
# old one. Generated once, reused forever.
STORE_DIR="${HOME}/.panelalpha/kan"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
OWNER_ENV="${STORE_DIR}/owner.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ] || [ ! -f "${OWNER_ENV}" ]; then
    # No '/', '+' or '=': these values are read back by a POSIX shell from an
    # unquoted env file, and the DB password also goes verbatim into a URL.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    OWNER_PASSWORD="$(openssl rand -base64 18 | tr -d '\n=/+')"
    # Better Auth requires a 32+ char secret; hex avoids anything an env file
    # or URL could misread.
    BETTER_AUTH_SECRET="$(openssl rand -hex 32)"
    OWNER_EMAIL="owner@kan.local"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Consumed by
# the web app and by the migrate/seed one-shots. Deleting this file does not
# reset the app; it strands the pgdata volume and every session.

# Discrete POSTGRES_PASSWORD (kept equal to db.env) and the DSN the app, the
# drizzle migration and the seed all connect with. Host 'db' is the compose
# service; database and user match the db service's env.
POSTGRES_PASSWORD=${PG_PASSWORD}
POSTGRES_URL=postgresql://kan:${PG_PASSWORD}@db:5432/kan_db

# Signs Better Auth sessions and CSRF tokens. Rotating it logs everyone out.
BETTER_AUTH_SECRET=${BETTER_AUTH_SECRET}
EOF
        cat > "${OWNER_ENV}" <<EOF
# The workspace owner seeded once into Postgres by pa/seed.mjs when no user
# exists yet. Kan has no admin CLI; this is written straight to the user/account
# tables the way Better Auth email sign-up would, so the owner can log in with
# email+password while public sign-up stays closed.
KAN_OWNER_EMAIL=${OWNER_EMAIL}
KAN_OWNER_NAME=Owner
KAN_OWNER_PASSWORD=${OWNER_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Kan on this account
===================

Kan is a Trello-style kanban board. Every board lives inside a workspace and is
private to its members; viewing or changing anything requires a login. Public
sign-up is switched OFF on this instance, so only the seeded owner (and anyone
the owner later invites) can get in.

OWNER LOGIN (seeded once, on the first deploy)
  URL:      <this account's URL>/login
  Email:    ${OWNER_EMAIL}
  Password: ${OWNER_PASSWORD}

  On first login the owner is prompted to create a workspace. Change the
  password from Settings after logging in if you like; a redeploy will not reset
  it (the seed only runs when no user exists).

REGISTRATION
  Public sign-up is disabled (NEXT_PUBLIC_DISABLE_SIGN_UP=true). New people join
  only by invitation from the owner. Invitations use magic links, which need
  SMTP -- see EMAIL below.

EMAIL
  Email features are switched off (NEXT_PUBLIC_DISABLE_EMAIL=true) because no
  SMTP is configured, so password reset and workspace invitations are not
  delivered. To enable them, set SMTP_* / EMAIL_FROM in this project's
  environment and set NEXT_PUBLIC_DISABLE_EMAIL to false.

SECRETS
  BETTER_AUTH_SECRET, the database password and the owner password live in
  ${STORE_DIR} (0600). They are generated once and reused on every redeploy,
  which is what keeps sessions, the database and the owner login working across
  rebuilds. Do not delete this directory.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The platform may write an account .env next to the compose file and merge the
# account's env_vars into it; make sure it exists so nothing referencing it
# aborts on a missing file.
touch .env
say "prepare complete"
