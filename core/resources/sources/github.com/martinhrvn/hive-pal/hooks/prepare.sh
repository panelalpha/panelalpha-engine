#!/bin/bash
set -e
cd ~/project

# Secrets are generated ONCE and reused on every redeploy. ~/project is wiped
# and re-cloned each deploy (engine#173), so they live in ~/.panelalpha/hive-pal/
# — the only writable, rebuild-surviving directory. Regenerating
# BETTER_AUTH_SECRET would invalidate every session; regenerating the DB
# password would lock the app out of the existing pgdata volume. Write only when
# missing. The admin login is the engine's (`credentials:` in panelalpha.yaml),
# in ~/.panelalpha/app-credentials.env.
SECRET_DIR="$HOME/.panelalpha/hive-pal"
SECRET_FILE="$SECRET_DIR/secrets.env"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true

if [ ! -f "$SECRET_FILE" ]; then
    # hex, so the DB password needs no URL-escaping inside DATABASE_URL.
    POSTGRES_PASSWORD=$(openssl rand -hex 16)
    # Better Auth wants 32+ chars for the session-signing secret.
    BETTER_AUTH_SECRET=$(openssl rand -base64 32 | tr -d '\n')
    ( umask 077
      cat > "$SECRET_FILE" <<EOF
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
DATABASE_URL=postgres://postgres:${POSTGRES_PASSWORD}@postgres:5432/beekeeper
BETTER_AUTH_SECRET=${BETTER_AUTH_SECRET}
EOF
    )
    chmod 600 "$SECRET_FILE"
fi

# Pre-pull so the engine's `docker compose up -d` starts instantly.
docker pull ghcr.io/martinhrvn/hive-pal:0.19.0
docker pull postgres:16-alpine
