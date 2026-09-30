#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do:
# persist this account's secrets where the next deploy will not delete them, and
# seed ~/project/.env with the tunables the account's env_vars merge over.
set -e
cd ~/project

say() { echo "[mobilizon] $*" >&2; }

# ~/.panelalpha/mobilizon/ survives; ~/project is emptied on every deploy
# (ProjectTree::clearContents), so a secret written there is regenerated on every
# rebuild -- a new SECRET_KEY_BASE/SECRET_KEY logs everyone out and invalidates
# outstanding confirmation/reset tokens, and a new DB password locks the app out
# of the pgdata volume that still holds the old one. Two files: the database
# has no business holding the app secrets. The admin login is the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env,
# and is delivered only to `init`, never to the long-running app container.
STORE_DIR="${HOME}/.panelalpha/mobilizon"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': the DB password is read back from an unquoted env file
    # and interpolated by both postgres and the app; keep it shell/DSN-clean.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # secret_key_base signs sessions/tokens; secret_key (Guardian) signs auth
    # JWTs. Both default to "changethis" in config/docker.exs and must be strong.
    SECRET_KEY_BASE="$(openssl rand -base64 64 | tr -d '\n=/+')"
    GUARDIAN_SECRET="$(openssl rand -base64 64 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgis container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its own database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file does not reset the application; it strands the pgdata volume and every
# session/confirmation/reset token signed under these keys.

# Endpoint + session/token signing. Rotating it logs everyone out.
MOBILIZON_INSTANCE_SECRET_KEY_BASE=${SECRET_KEY_BASE}

# Guardian (auth JWT) signing secret. Rotating it invalidates every login token.
MOBILIZON_INSTANCE_SECRET_KEY=${GUARDIAN_SECRET}

# Same password as POSTGRES_PASSWORD in db.env; the app connects with it.
MOBILIZON_DATABASE_PASSWORD=${PG_PASSWORD}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# ~/project/.env: the base ProjectEnvironment::apply() merges the account's
# env_vars over it, and the services read it as their first env_file, so a key
# here is a default the panel can override. Nothing secret goes here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}

# Closed by default: no SMTP is configured, so nobody could confirm a sign-up.
# The seeded admin does not need it. Opening it also needs SMTP (see panelalpha.yaml).
write_default MOBILIZON_INSTANCE_REGISTRATIONS_OPEN false
# Shown in the UI and federation metadata; override to taste.
write_default MOBILIZON_INSTANCE_NAME Mobilizon
# config/docker.exs default language; options include en, fr, de, es.
write_default MOBILIZON_INSTANCE_DEFAULT_LANGUAGE en
# error|warning|info|debug; error keeps logs quiet in production.
write_default MOBILIZON_LOGLEVEL error
say "wrote defaults to ~/project/.env"
