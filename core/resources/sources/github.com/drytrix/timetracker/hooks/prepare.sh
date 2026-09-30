#!/bin/bash
# Generate the secrets and the admin password once, where a redeploy will not
# wipe them (~/project is emptied on every deploy, engine#173), and seed .env.
set -e
cd ~/project

say() { echo "[timetracker] $*" >&2; }

STORE="${HOME}/.panelalpha/timetracker"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/admin.env" ]; then
    PG_PASSWORD="$(rnd 24)"
    ADMIN_PASSWORD="$(rnd 18)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${STORE}/db.env"
        # SETTINGS_ENCRYPTION_KEY is a Fernet key (urlsafe base64 of 32 bytes).
        cat > "${STORE}/app.env" <<ENV_EOF
# Written once: the database holds this password, sessions are signed with
# SECRET_KEY and stored integration secrets are encrypted with the Fernet key.
SECRET_KEY=$(openssl rand -hex 32)
SETTINGS_ENCRYPTION_KEY=$(openssl rand -base64 32 | tr '+/' '-_')
DATABASE_URL=postgresql+psycopg2://timetracker:${PG_PASSWORD}@db:5432/timetracker
ENV_EOF
        printf 'TT_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${STORE}/admin.env"
        cat > "${STORE}/credentials.txt" <<NOTE_EOF
TimeTracker on this account
===========================

The administrator was given this password on the first deploy, before the
site was reachable. Self-registration is off and the first-run wizard was
completed with its defaults (Admin > Settings changes them).

ADMIN LOGIN
  URL:      <this account's URL>/login
  Username: admin
  Password: ${ADMIN_PASSWORD}

Change the password under your profile. The admin adds users from
Admin > Users. A redeploy resets nothing: data lives on the db_data,
app_data and app_uploads volumes.
NOTE_EOF
    )
    say "secrets and admin credentials written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# Defaults the panel's env vars can override; nothing secret here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default TZ UTC
write_default CURRENCY EUR
write_default ENABLE_TELEMETRY false
