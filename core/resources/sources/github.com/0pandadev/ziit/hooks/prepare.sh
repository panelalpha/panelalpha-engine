#!/bin/bash
set -e
cd ~/project

# Secrets are generated ONCE and reused on every redeploy. ~/project is wiped and
# re-cloned each deploy (engine#173), so they live in ~/.panelalpha/ziit/ -- the
# only writable, rebuild-surviving directory. Rotating NUXT_PASETO_KEY logs every
# user out; rotating the DB password locks the app out of the existing pgdata
# volume. Write only when missing.
SECRET_DIR="$HOME/.panelalpha/ziit"
DB_ENV="$SECRET_DIR/db.env"
APP_ENV="$SECRET_DIR/ziit.env"
CRED_FILE="$SECRET_DIR/credentials.txt"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true

OWNER_EMAIL="owner@ziit.local"

if [ ! -f "$APP_ENV" ]; then
    # hex, so the password needs no URL-escaping inside NUXT_DATABASE_URL.
    DB_PASSWORD=$(openssl rand -hex 16)
    # paseto-ts v4 local key: literally k4.local.<base64 of 32 random bytes>.
    PASETO_KEY="k4.local.$(openssl rand -base64 32 | tr -d '\n')"
    # /api/admin validates the key with zod z.base64(); must be valid base64.
    ADMIN_KEY=$(openssl rand -base64 64 | tr -d '\n')
    # 16 hex + fixed classes => satisfies the >=12 upper/lower/number/special rule.
    OWNER_PASSWORD="Zt1!$(openssl rand -hex 16)"
    ( umask 077
      cat > "$DB_ENV" <<EOF
POSTGRES_PASSWORD=${DB_PASSWORD}
EOF
      cat > "$APP_ENV" <<EOF
NUXT_DATABASE_URL=postgresql://postgres:${DB_PASSWORD}@postgres:5432/ziit?schema=public
NUXT_PASETO_KEY=${PASETO_KEY}
NUXT_ADMIN_KEY=${ADMIN_KEY}
EOF
      cat > "$CRED_FILE" <<EOF
Ziit -- self-hosted WakaTime alternative.

Registration is OPEN by app design (NUXT_DISABLE_REGISTRATION=false). Register
the owner account below at your site's /register (no email confirmation needed),
then, if you want a single-user instance, set NUXT_DISABLE_REGISTRATION=true in
the project's environment and redeploy to close public sign-up.

Suggested owner account (register it yourself; not auto-seeded):
  email:    ${OWNER_EMAIL}
  password: ${OWNER_PASSWORD}

After logging in, open Settings to read/regenerate your per-user API key, then
point your editor's WakaTime-compatible plugin at:
  API URL: <your site>/api/external
  API key: <the key from Settings>

NUXT_ADMIN_KEY is a separate GLOBAL admin API secret (Bearer on /api/admin),
not tied to any user account:
  ${ADMIN_KEY}

These values are reused on every redeploy; delete this directory only if you
also drop the ziit-pgdata volume.
EOF
    )
    chmod 600 "$DB_ENV" "$APP_ENV" "$CRED_FILE"
fi

# Pre-pull so the engine's `docker compose up -d` starts instantly.
docker pull "${ZIIT_IMAGE:-ghcr.io/0pandadev/ziit:v1.1.2}" || true
docker pull "${ZIIT_DB_IMAGE:-timescale/timescaledb:2.22.1-pg17}" || true
