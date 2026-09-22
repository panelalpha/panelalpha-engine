#!/bin/bash
set -e
cd ~/project

# Secrets are generated ONCE and reused on every redeploy. ~/project is wiped
# and re-cloned each deploy (engine#173), so they live in ~/.panelalpha/hive-pal/
# — the only writable, rebuild-surviving directory. Regenerating
# BETTER_AUTH_SECRET would invalidate every session; regenerating the DB
# password would lock the app out of the existing pgdata volume. Write only when
# missing.
SECRET_DIR="$HOME/.panelalpha/hive-pal"
SECRET_FILE="$SECRET_DIR/secrets.env"
ADMIN_FILE="$SECRET_DIR/admin-credentials.txt"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true

ADMIN_EMAIL="admin@hive-pal.local"

if [ ! -f "$SECRET_FILE" ]; then
    # hex, so the DB password needs no URL-escaping inside DATABASE_URL.
    POSTGRES_PASSWORD=$(openssl rand -hex 16)
    # Better Auth wants 32+ chars for the session-signing secret.
    BETTER_AUTH_SECRET=$(openssl rand -base64 32 | tr -d '\n')
    ADMIN_PASSWORD=$(openssl rand -hex 16)
    ( umask 077
      cat > "$SECRET_FILE" <<EOF
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
DATABASE_URL=postgres://postgres:${POSTGRES_PASSWORD}@postgres:5432/beekeeper
BETTER_AUTH_SECRET=${BETTER_AUTH_SECRET}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
      cat > "$ADMIN_FILE" <<EOF
Hive-Pal owner admin (seeded on first boot from ADMIN_EMAIL / ADMIN_PASSWORD).
Log in at your site with email + password.

  email:    ${ADMIN_EMAIL}
  password: ${ADMIN_PASSWORD}

Change the password in the app after first login. These values are reused on
every redeploy; delete this directory only if you also drop the pgdata volume.
EOF
    )
    chmod 600 "$SECRET_FILE" "$ADMIN_FILE"
fi

# Pre-pull so the engine's `docker compose up -d` starts instantly.
docker pull ghcr.io/martinhrvn/hive-pal:0.19.0
docker pull postgres:16-alpine
