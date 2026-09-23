#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do:
# persist this account's secrets where the next deploy will not delete them, and
# seed ~/project/.env with the tunables the account env_vars merge over.
set -e
cd ~/project

say() { echo "[memex] $*" >&2; }

# ~/.panelalpha/memex/ survives; ~/project is emptied on every deploy
# (ProjectTree::clearContents), so a secret written there is regenerated on every
# rebuild -- a new SECRET_KEY_BASE logs everyone out, a new CLOAK_KEY makes stored
# 2FA authenticator secrets unreadable, and a new DB password locks the app out of
# the pgdata volume that still holds the old one. Two files: the database has no
# business holding the app secrets, and compose delivers each as its own env_file.
STORE_DIR="${HOME}/.panelalpha/memex"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': the password is read back from an unquoted env file and
    # interpolated into the ecto:// DSN below.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # runtime.exs requires SECRET_KEY_BASE in prod; priv/random.sh ships 64 bytes,
    # so match that length.
    SECRET_KEY_BASE="$(openssl rand -base64 48 | tr -d '\n')"
    # CLOAK_KEY must decode to EXACTLY 32 bytes or runtime.exs raises; base64 of 32
    # random bytes does exactly that.
    CLOAK_KEY="$(openssl rand -base64 32 | tr -d '\n')"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its own database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file does not reset the application; it strands the pgdata volume and the 2FA
# secrets encrypted under CLOAK_KEY.

# Signs session cookies and tokens. Rotating it logs everyone out.
SECRET_KEY_BASE=${SECRET_KEY_BASE}

# 32 bytes, base64. Encrypts 2FA authenticator secrets at rest. Rotating it makes
# existing authenticator pairings unreadable -- back it up, do not regenerate.
CLOAK_KEY=${CLOAK_KEY}

# runtime.exs reads the database from DATABASE_URL. Same password as
# POSTGRES_PASSWORD in db.env; host is the compose service name.
DATABASE_URL=ecto://memex:${PG_PASSWORD}@db/memex
EOF
        cat > "${NOTE}" <<'EOF'
memEx on this account
=====================

memEx is a self-hosted structured personal knowledge base (notes, contexts and
pipelines). There is no seeded administrator, and that is deliberate: memEx makes
the FIRST registered user an admin, and closes public registration once an admin
exists.

FIRST RUN
  1. Open this account's URL. memEx's home page loads.
  2. Click Register and create your account (email + password). You are the
     admin. Registration is then invite-only for everyone else.
  3. Confirm your email (see EMAIL below) -- memEx will NOT let you log in
     until the account is confirmed.
  4. Log in and start adding notes, contexts and pipelines.

REGISTRATION
  Defaults to invite-only (REGISTRATION=invite). Set REGISTRATION=public in this
  project's environment variables to let anyone sign up.

EMAIL (REQUIRED to finish onboarding)
  memEx refuses login until the account's email is confirmed, and it confirms by
  emailing a one-time link. DISABLE_EMAIL=true by default, so with no SMTP the
  confirmation (and password-reset) messages are written to the app container log
  instead of being sent. Two supported ways to confirm:

    (a) RECOMMENDED -- deliver by email. Set DISABLE_EMAIL=false and the SMTP_*
        variables in this project's environment, then register:
           DISABLE_EMAIL=false
           SMTP_HOST=smtp.example.com
           SMTP_USERNAME=...           SMTP_PASSWORD=...
           SMTP_PORT=587  (optional)   SMTP_SSL=false (optional)
        The confirmation link arrives in your inbox like any user's.

    (b) One-person instance without SMTP -- read the link once from the log:
           docker compose logs memex | grep users/confirm
        and open the printed https://<this-domain>/users/confirm/<token> URL.

  Inbound mail is not configured and is out of scope.

SECRETS
  SECRET_KEY_BASE, CLOAK_KEY and the database password live in
  ~/.panelalpha/memex/ (0600). They are generated once and never rewritten; a
  rebuild reuses them, which is what keeps your login, your 2FA pairings and your
  data working across redeploys.
EOF
    )
    say "secrets written to ${STORE_DIR}; onboarding notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# ~/project/.env: the base ProjectEnvironment::apply() merges the account's
# env_vars over it, and the app reads it as its first env_file, so a key here is
# a default the panel can override. Nothing secret goes here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}

# Off by default: no SMTP is configured, so mail is logged, not sent. See notes.
write_default DISABLE_EMAIL true
# Invite-only: the first user still becomes admin (allow_registration? is true
# while no admin exists), after which public sign-up is closed. Set `public` to
# open it.
write_default REGISTRATION invite
# runtime.exs default.
write_default LOCALE en_US
say "wrote defaults to ~/project/.env"
