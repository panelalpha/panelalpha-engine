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
# of the pgdata volume that still holds the old one. Three files: the database
# has no business holding the app secrets, and the admin password must never
# reach the long-running app container, so it is delivered only to `init`.
STORE_DIR="${HOME}/.panelalpha/mobilizon"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ] || [ ! -f "${ADMIN_ENV}" ]; then
    # No '/', '+' or '=': the DB password is read back from an unquoted env file
    # and interpolated by both postgres and the app; keep it shell/DSN-clean.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # secret_key_base signs sessions/tokens; secret_key (Guardian) signs auth
    # JWTs. Both default to "changethis" in config/docker.exs and must be strong.
    SECRET_KEY_BASE="$(openssl rand -base64 64 | tr -d '\n=/+')"
    GUARDIAN_SECRET="$(openssl rand -base64 64 | tr -d '\n=/+')"
    # The seeded admin's login password. Alphanumeric only so it is easy to
    # copy from credentials.txt and safe in an env file.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+' | cut -c1-24)"
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
        cat > "${ADMIN_ENV}" <<EOF
# Read only by the one-shot init service, never by the running app. The
# seeded administrator's login password; generated once and reused so a rebuild
# does not change the owner's credentials.
MOBILIZON_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Mobilizon on this account
=========================

Mobilizon is a federated (ActivityPub) events and groups platform. An
administrator account has been created for you and is already confirmed -- you
can log in immediately, no email step required.

ADMIN LOGIN
  Email:    admin@<this account's domain>   (shown in the app after first login;
            the local part is "admin", the domain is this account's public host)
  Password: ${ADMIN_PASSWORD}

  Open this account's URL, click Login, and sign in with the address above and
  this password. You land with an "admin" profile already created, so you can
  create groups and events straight away. Change the password from your account
  settings after first login if you wish.

PUBLIC REGISTRATION (off by default)
  MOBILIZON_INSTANCE_REGISTRATIONS_OPEN=false, so only the seeded admin exists
  and no mail server is needed. To let the public sign up you must ALSO give
  Mobilizon an SMTP server, because a self-registered user cannot log in until
  they confirm their email and Mobilizon sends that link by SMTP. Set, in this
  project's environment variables:
     MOBILIZON_INSTANCE_REGISTRATIONS_OPEN=true
     MOBILIZON_SMTP_SERVER=smtp.example.com
     MOBILIZON_SMTP_PORT=587
     MOBILIZON_SMTP_USERNAME=...      MOBILIZON_SMTP_PASSWORD=...
     MOBILIZON_SMTP_TLS=if_available  MOBILIZON_SMTP_AUTH=if_available
     MOBILIZON_INSTANCE_EMAIL=noreply@example.com
  Until SMTP is set, any confirmation/reset link is only written to the app
  container log. Mobilizon receives no inbound mail; that is out of scope.

SECRETS
  The database password, MOBILIZON_INSTANCE_SECRET_KEY_BASE,
  MOBILIZON_INSTANCE_SECRET_KEY and this admin password live in
  ~/.panelalpha/mobilizon/ (0600). They are generated once and never rewritten;
  a rebuild reuses them, which keeps your login, tokens and data working across
  redeploys.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
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
# The seeded admin does not need it. See credentials.txt to open registration.
write_default MOBILIZON_INSTANCE_REGISTRATIONS_OPEN false
# Shown in the UI and federation metadata; override to taste.
write_default MOBILIZON_INSTANCE_NAME Mobilizon
# config/docker.exs default language; options include en, fr, de, es.
write_default MOBILIZON_INSTANCE_DEFAULT_LANGUAGE en
# error|warning|info|debug; error keeps logs quiet in production.
write_default MOBILIZON_LOGLEVEL error
say "wrote defaults to ~/project/.env"
