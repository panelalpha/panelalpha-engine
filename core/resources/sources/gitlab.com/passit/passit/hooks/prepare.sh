#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/
# are in place, before `docker compose up`. Two jobs the compose file cannot do
# for itself: put this account's secrets somewhere the next deploy will not
# delete, and seed ~/project/.env with tunables the account's env_vars merge
# over.
set -e
cd ~/project

say() { echo "[passit] $*" >&2; }

# ~/.panelalpha/passit/ survives the clone; ~/project is emptied on every
# deploy (ProjectTree::clearContents), so a secret written there is regenerated
# on every rebuild -- a new SECRET_KEY logs everyone out and invalidates every
# outstanding confirmation/reset link, and a new DB password locks the app out
# of the pgdata volume that still holds the old one. Two files: the database
# container has no business holding SECRET_KEY, and compose delivers each as
# its own env_file.
STORE_DIR="${HOME}/.panelalpha/passit"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': the password is read back by a POSIX shell from an
    # unquoted env file and interpolated into a postgres:// DSN.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # settings.py requires SECRET_KEY when DEBUG is off; hex avoids anything an
    # env file could misread.
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it here would lock the application out of its own
# database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting
# this file does not reset the application; it strands the pgdata volume.

# settings.py:35 requires this with DEBUG off. It signs session cookies,
# confirmation codes and password-reset tokens.
SECRET_KEY=${SECRET_KEY}

# settings.py reads the database from DATABASE_URL (env.db). Same password as
# POSTGRES_PASSWORD in db.env.
DATABASE_URL=postgres://passit:${PG_PASSWORD}@db:5432/passit
EOF
        cat > "${NOTE}" <<'EOF'
Passit on this account
======================

Passit is an end-to-end-encrypted password manager. There is no seeded
administrator, and that is deliberate: a Passit account's encryption keys are
derived from the password by the browser app when you REGISTER, so an account
created any other way (e.g. Django's createsuperuser) cannot decrypt anything
and cannot be logged into from the app.

FIRST RUN
  1. Open this account's URL. The Passit web app loads.
  2. Click Register and create your account with your email and a password.
     Keep the backup code it shows you -- it is the only way to recover the
     vault if you forget the password.
  3. Confirm your email (see EMAIL below), then log in and start adding
     passwords and groups.

EMAIL (REQUIRED before an account can be used)
  Passit will not let a user open their vault until their email address is
  confirmed, and it confirms by sending a code by email. Configure SMTP in this
  project's environment variables before registering:

     EMAIL_URL=submission://user:password@smtp.example.com:587
     DEFAULT_FROM_EMAIL=passit@example.com

  (EMAIL_URL is django-environ's email DSN; see Passit's install docs.) Until
  SMTP is set the confirmation code is written to the app container log instead
  of being emailed, so no outside user can confirm their account.

SECRETS
  SECRET_KEY and the database password live in ~/.panelalpha/passit/ (0600).
  They are generated once and never rewritten; a rebuild reuses them, which is
  what keeps your vault and your login working across redeploys.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# ~/project/.env: the base ProjectEnvironment::apply() merges the account's
# env_vars over, and both services read it as their first env_file, so a key
# here is a default the panel can override. Nothing secret goes here (mode 644,
# copied to .env.default).
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}

# settings.py:34 -- never on in production. Named here too so a stray IS_DEBUG
# in the account env is the operator's explicit choice, not a default.
write_default IS_DEBUG False
# settings.py:53. TLS is terminated at the engine's proxy and the app speaks
# plain http inside the container; with this True granian would 301 every
# request to https on a URL it thinks is http and loop.
write_default SECURE_SSL_REDIRECT False
# Django admin at /admin/ is a second, session-only login surface that lists
# every registered email. It is not the vault (that is end-to-end encrypted and
# lives behind the API), and its login POST is rejected by CSRF over the proxy
# anyway, so it is off by default. Set True to manage users from Django admin.
write_default ENABLE_DJANGO_ADMIN False
say "wrote defaults to ~/project/.env"
