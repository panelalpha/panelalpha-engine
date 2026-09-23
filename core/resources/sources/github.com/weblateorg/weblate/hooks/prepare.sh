#!/bin/bash
# Runs in the account shell after the clone and after overrides/ are in place,
# before `docker compose up`. Seeds Weblate's secrets once, keeps them somewhere
# a redeploy will not delete, then re-writes the env_files the compose reads.
# Rotating any of these breaks an existing instance: a new DB or Redis password
# locks the app out of its volume, a new SECRET_KEY invalidates every session
# and stored token, a new admin password silently changes the login.
set -e

# ~/project is wiped every deploy (engine#173); ~/.panelalpha survives, so the
# secrets live there and are generated only on the first deploy.
STORE="$HOME/.panelalpha/weblate"
mkdir -p "$STORE"
chmod 700 "$HOME/.panelalpha" "$STORE"

# gen_or_read <file> <generator...>: create with the generator if missing, print.
gen_or_read() {
    local f="$1"; shift
    if [ ! -s "$f" ]; then
        "$@" > "$f"
        chmod 600 "$f"
    fi
    cat "$f"
}

# hex only: reads back cleanly from an env file and a URL (no /,+,=,#,@,:).
DB_PASSWORD=$(gen_or_read "$STORE/db_password" openssl rand -hex 16)
REDIS_PASSWORD=$(gen_or_read "$STORE/redis_password" openssl rand -hex 16)
SECRET_KEY=$(gen_or_read "$STORE/secret_key" openssl rand -hex 32)
ADMIN_PASSWORD=$(gen_or_read "$STORE/admin_password" openssl rand -hex 16)

umask 077

# Postgres sidecar reads this; POSTGRES_PASSWORD is baked into the data volume
# on first init and must never change afterwards.
cat > "$STORE/db.env" <<EOF
POSTGRES_PASSWORD=${DB_PASSWORD}
EOF

# Redis sidecar reads this for --requirepass.
cat > "$STORE/redis.env" <<EOF
REDIS_PASSWORD=${REDIS_PASSWORD}
EOF

# Weblate app secrets. POSTGRES_PASSWORD must equal the sidecar's, REDIS_PASSWORD
# too. Kept out of the compose YAML so the placeholder filler never sees them.
cat > "$STORE/app.env" <<EOF
POSTGRES_PASSWORD=${DB_PASSWORD}
REDIS_PASSWORD=${REDIS_PASSWORD}
WEBLATE_SECRET_KEY=${SECRET_KEY}
WEBLATE_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
chmod 600 "$STORE/db.env" "$STORE/redis.env" "$STORE/app.env"

# Record the admin login for the operator (idempotent).
cat > "$STORE/credentials.txt" <<EOF
Weblate admin login
  username: admin
  password: ${ADMIN_PASSWORD}
EOF
chmod 600 "$STORE/credentials.txt"

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull weblate/weblate:2026.9.1.2 || true
docker pull postgres:17-alpine || true
docker pull redis:7-alpine || true
