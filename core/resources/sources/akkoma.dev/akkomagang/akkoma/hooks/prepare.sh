#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do:
# persist this account's secrets where the next deploy will not delete them, and
# seed ~/project/.env with the tunables the account's env_vars merge over.
set -e
cd ~/project

say() { echo "[akkoma] $*" >&2; }

# ~/.panelalpha/akkoma/ survives; ~/project is emptied on every deploy
# (ProjectTree::clearContents), so a secret written there would regenerate on
# every rebuild -- a new DB password would lock the app out of the pgdata volume
# that still holds the old one, and a new admin password would not match the one
# already in the owner's vault. Akkoma's OWN signing secrets (secret_key_base,
# signing_salt, VAPID keys) are NOT here: config/docker.exs generates them into
# /var/lib/akkoma/secret.exs on first boot, and that path is a named volume, so
# they persist untouched across rebuilds. Two files: the database has no business
# holding the admin password, and it must never reach the long-running app.
STORE_DIR="${HOME}/.panelalpha/akkoma"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ] || [ ! -f "${ADMIN_ENV}" ]; then
    # No '/', '+' or '=': the DB password is read back from an unquoted env file
    # and used by both postgres and the app.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # The seeded admin's login password. Alphanumeric only so it is easy to copy
    # from credentials.txt and safe unquoted in an env file / shell.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+' | cut -c1-24)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the application out of its own database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. The app reads
# DB_PASS to reach postgres; it is the same value as POSTGRES_PASSWORD in db.env.
# Akkoma's signing secrets are generated separately into the akkoma_var volume
# (/var/lib/akkoma/secret.exs) and are not stored here.
DB_PASS=${PG_PASSWORD}
EOF
        cat > "${ADMIN_ENV}" <<EOF
# Read only by the one-shot init service, never by the running app. The seeded
# administrator's login password; generated once and reused so a rebuild does
# not change the owner's credentials.
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Akkoma on this account
======================

Akkoma is a federated (ActivityPub, Mastodon-compatible) microblogging server.
An administrator account has been created for you and is already confirmed --
you can log in immediately, no email step required.

ADMIN LOGIN
  Username: admin
  Email:    admin@<this account's public host>
  Password: ${ADMIN_PASSWORD}

  Open this account's URL. The Akkoma (pleroma-fe) web UI loads; click Login and
  sign in with username "admin" and this password. The admin/moderation console
  is at /pleroma/admin . Change the password from your account settings after
  first login if you wish.

PUBLIC REGISTRATION (off by default)
  registrations_open is false, so only the seeded admin exists and no mail
  server is needed. To let the public sign up you must ALSO give Akkoma an SMTP
  server, because a self-registered user cannot log in until they confirm their
  email and Akkoma sends that link by SMTP. Set, in this project's environment
  variables:
     INSTANCE_REGISTRATIONS_OPEN=true
  and configure a mailer (Pleroma.Emails.Mailer) via /var/lib/akkoma/config.exs
  or the admin console. Until SMTP is set, any confirmation/reset link is only
  written to the app container log. Akkoma receives no inbound mail; that is out
  of scope.

SECRETS
  The database password and this admin password live in ~/.panelalpha/akkoma/
  (0600). Akkoma's own signing secrets (secret_key_base, signing_salt, web-push
  VAPID keys) live in the akkoma_var docker volume at /var/lib/akkoma/secret.exs.
  All are generated once and never rewritten; a rebuild reuses them, which keeps
  your login, your sessions and your data working across redeploys.
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
write_default INSTANCE_REGISTRATIONS_OPEN false
# Shown in the UI and federation metadata; override to taste.
write_default INSTANCE_NAME Akkoma
# The official prebuilt release the image is built from. Override to move
# version/branch, e.g. .../stable/akkoma-amd64.zip or .../develop/akkoma-amd64.zip.
write_default AKKOMA_RELEASE_URL https://akkoma-updates.s3-website.fr-par.scw.cloud/v3.20.0/akkoma-amd64.zip
say "wrote defaults to ~/project/.env"
