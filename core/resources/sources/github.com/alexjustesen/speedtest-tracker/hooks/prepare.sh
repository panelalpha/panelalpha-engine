#!/bin/bash
# Generate APP_KEY and the admin credentials once, where a redeploy will not
# wipe them (~/project is emptied on every deploy, engine#173), and seed .env.
set -e
cd ~/project

say() { echo "[speedtest] $*" >&2; }

STORE="${HOME}/.panelalpha/speedtest"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '\n=/+')"
    ADMIN_EMAIL="admin@speedtest.local"
    (
        umask 077
        cat > "${STORE}/app.env" <<ENV_EOF
# Written once. APP_KEY encrypts sessions and stored settings; the ADMIN_*
# values are read only by the first migration, which creates the admin.
APP_KEY=base64:$(openssl rand -base64 32)
ADMIN_NAME=Admin
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
ENV_EOF
        cat > "${STORE}/credentials.txt" <<NOTE_EOF
Speedtest Tracker on this account
=================================

The administrator was created with this password by the first deploy.
There is no self-registration.

ADMIN LOGIN
  URL:      <this account's URL>/admin/login
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

Change both under your profile. Changing ADMIN_* later has no effect: they
are read only when the database is first created. Results and settings live
on the speedtest-config volume and survive a redeploy.
NOTE_EOF
    )
    say "APP_KEY and admin credentials written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# Defaults the panel's env vars can override; nothing secret here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default TZ UTC
write_default APP_TIMEZONE UTC
write_default DISPLAY_TIMEZONE UTC
