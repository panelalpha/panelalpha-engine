#!/bin/bash
# Generates the admin login and session secrets once into ~/.panelalpha/chronoframe
# (~/project is wiped every deploy); later deploys reuse them.
set -e
cd ~/project

STORE="$HOME/.panelalpha/chronoframe"
ENV_FILE="$STORE/chronoframe.env"
NOTE="$STORE/credentials.txt"
mkdir -p "$STORE"
chmod 700 "$HOME/.panelalpha" "$STORE" 2>/dev/null || true

if [ ! -f "$ENV_FILE" ]; then
    ADMIN_EMAIL="admin@chronoframe.local"
    # Alphanumeric plus fixed classes: no character an env file or JSON body mangles.
    ADMIN_PASSWORD="Cf1-$(openssl rand -hex 16)"
    (
        umask 077
        cat > "$ENV_FILE" <<ENV
CFRAME_ADMIN_EMAIL=${ADMIN_EMAIL}
CFRAME_ADMIN_NAME=admin
CFRAME_ADMIN_PASSWORD=${ADMIN_PASSWORD}
NUXT_SESSION_PASSWORD=$(openssl rand -hex 32)
NUXT_OG_IMAGE_SECRET=$(openssl rand -hex 32)
ENV
        cat > "$NOTE" <<NOTE
ChronoFrame admin login (seeded on the first boot, when the database has no user):
  URL:      <this site>/signin
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

The seed runs only while the users table is empty, so changing the password in
the app is permanent; a redeploy does not reset it. Photos and the SQLite
database live on the chronoframe-data volume. Keep this directory: the session
secret signs every login cookie.
NOTE
    )
    chmod 600 "$ENV_FILE" "$NOTE"
fi

docker pull ghcr.io/hoshinosuzumi/chronoframe:0.14.1 >/dev/null 2>&1 || true
