#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do:
# persist this account's secrets where the next deploy will not delete them, and
# seed ~/project/.env with the tunables the account env_vars merge over.
set -e
cd ~/project

say() { echo "[mindwendel] $*" >&2; }

# ~/.panelalpha/mindwendel/ survives; ~/project is emptied on every deploy
# (engine#173), so a secret written there is regenerated on every rebuild -- a
# new SECRET_KEY_BASE logs every session out, a new DB password locks the app out
# of the pgdata volume that still holds the old one. Two files: the database has
# no business holding the app secret, and compose delivers each as its own
# env_file.
STORE_DIR="${HOME}/.panelalpha/mindwendel"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # No '/', '+' or '=': the password is read back from an unquoted env file and
    # handed to postgres and to the app as DATABASE_USER_PASSWORD.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # runtime.exs requires SECRET_KEY_BASE in prod; phx.gen.secret is 64 bytes.
    SECRET_KEY_BASE="$(openssl rand -base64 64 | tr -d '\n')"
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
# file does not reset the application; it strands the pgdata volume.

# Signs session cookies and tokens. Rotating it drops every active session.
SECRET_KEY_BASE=${SECRET_KEY_BASE}

# The app's database password. Same value as POSTGRES_PASSWORD in db.env; the
# host and user are fixed compose coordinates set in docker-compose.yml.
DATABASE_USER_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${NOTE}" <<'EOF'
Mindwendel on this account
==========================

Mindwendel is a self-hosted brainstorming tool. There is no login and no
seeded administrator -- that is by design.

HOW IT WORKS
  1. Open this account's URL. The home page loads with a "create board" form.
  2. Create a brainstorming board. You get two secret links:
       - the board link  /brainstormings/<uuid>          (view + contribute)
       - the admin link   /admin/brainstormings/<uuid>    (edit/delete/export)
     Both ids are unguessable UUIDs. Share the board link with anyone you want
     to brainstorm with; keep the admin link private.
  3. Anyone with the board link adds ideas, likes them and sorts them into
     lanes. The home page only ever lists the boards YOU created (remembered in
     your own browser) -- there is no global list of everyone's boards.

FILE ATTACHMENTS ON IDEAS (off by default)
  Attaching files to ideas needs an S3/MinIO object store plus an encryption
  key, which this panel does not host, so MW_FEATURE_IDEA_FILE_UPLOAD defaults
  to false. Everything else -- boards, ideas, likes, lanes, labels, CSV export
  -- works without it. To turn it on, run your own S3-compatible store and set
  in this project's environment:
     MW_FEATURE_IDEA_FILE_UPLOAD=true
     OBJECT_STORAGE_SCHEME=https://   OBJECT_STORAGE_HOST=...   OBJECT_STORAGE_PORT=...
     OBJECT_STORAGE_REGION=...        OBJECT_STORAGE_USER=...   OBJECT_STORAGE_PASSWORD=...
     VAULT_ENCRYPTION_KEY_BASE64=$(openssl rand -base64 32)

SECRETS
  SECRET_KEY_BASE and the database password live in
  ~/.panelalpha/mindwendel/ (0600). They are generated once and never rewritten;
  a rebuild reuses them, which keeps your sessions and your data working across
  redeploys.
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

# Off by default: enabling it requires an external S3 store and a vault key (see
# credentials.txt). runtime.exs raises on boot if it is on and those are unset.
write_default MW_FEATURE_IDEA_FILE_UPLOAD false
# runtime.exs default; options include en, de, fr.
write_default MW_DEFAULT_LOCALE en
# Boards (and their ideas) are auto-removed this many days after last access.
write_default MW_FEATURE_BRAINSTORMING_REMOVAL_AFTER_DAYS 30
say "wrote defaults to ~/project/.env"
