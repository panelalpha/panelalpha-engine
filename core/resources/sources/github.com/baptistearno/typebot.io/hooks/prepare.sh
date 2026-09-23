#!/bin/bash
set -e
cd ~/project

# Rebuild-surviving state lives under ~/.panelalpha (the only writable dir the
# engine keeps across a rebuild); everything in ~/project is recreated. Postgres
# data and the generated secrets both go there so a redeploy keeps the database
# and, crucially, ENCRYPTION_SECRET — Typebot encrypts saved integration
# credentials with it, and a new value orphans every one of them.
STATE="$HOME/.panelalpha/typebot"
mkdir -p "$STATE/db"
SECRETS="$STATE/secrets.env"

if [ ! -f "$SECRETS" ]; then
  umask 077
  # ENCRYPTION_SECRET must be exactly 32 chars (@typebot.io/env: z.string().length(32));
  # hex-16 is 32 chars and survives being embedded in YAML/URLs without quoting.
  {
    echo "ENCRYPTION_SECRET=$(openssl rand -hex 16)"
    echo "POSTGRES_PASSWORD=$(openssl rand -hex 16)"
    # ADMIN_EMAIL must be a real, legit address: Typebot validates it with
    # mailchecker before it will send the magic-link code, and it is where the
    # code is delivered. An invented value (admin@<hostname>) is rejected as
    # "email-not-legit", so it is the operator's to set (with SMTP) — passed in
    # as ADMIN_EMAIL, persisted here on first deploy if present.
    [ -n "${ADMIN_EMAIL:-}" ] && echo "ADMIN_EMAIL=${ADMIN_EMAIL}"
  } > "$SECRETS"
fi
# shellcheck disable=SC1090
. "$SECRETS"

# The project .env is read by docker compose for ${VAR} interpolation and as the
# apps' env_file. NEXTAUTH_URL / NEXT_PUBLIC_VIEWER_URL are NOT set here — they
# are placed in the compose `environment:` as http://localhost:3000 so the
# engine's ComposePlaceholders rewrites them to the account's public URL.
cat > .env <<EOF
ENCRYPTION_SECRET=${ENCRYPTION_SECRET}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
DATABASE_URL=postgresql://postgres:${POSTGRES_PASSWORD}@typebot-db:5432/typebot
ADMIN_EMAIL=${ADMIN_EMAIL}
PGDATA_DIR=${STATE}/db
EOF
chmod 600 .env

# Pre-pull so the engine's `docker compose up -d` starts without a long stall.
docker pull baptistearno/typebot-builder:3.19.0
docker pull baptistearno/typebot-viewer:3.19.0
docker pull postgres:16
