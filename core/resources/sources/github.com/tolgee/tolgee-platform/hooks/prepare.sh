#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. It seeds Tolgee's secrets once and keeps
# them somewhere a redeploy will not delete, then re-writes the env_files the
# compose reads. Rotating any of these would break an existing instance: a new
# DB password locks the app out of the postgres volume, a new JWT secret
# invalidates every session, a new admin password silently changes the login.
set -e

# ~/project is wiped every deploy (engine#173); ~/.panelalpha survives, so the
# secrets live there and are generated only on the first deploy.
STORE="$HOME/.panelalpha/tolgee"
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

# hex only: reads back cleanly from an env file and a JDBC URL (no /,+,=,#,@,:).
DB_PASSWORD=$(gen_or_read "$STORE/db_password" openssl rand -hex 16)
JWT_SECRET=$(gen_or_read "$STORE/jwt_secret" openssl rand -hex 32)
ADMIN_PASSWORD=$(gen_or_read "$STORE/admin_password" openssl rand -hex 16)

umask 077

# Postgres sidecar reads this; POSTGRES_PASSWORD is baked into the data volume
# on first init and must never change afterwards.
cat > "$STORE/db.env" <<EOF
POSTGRES_PASSWORD=${DB_PASSWORD}
EOF

# Tolgee app secrets. SPRING_DATASOURCE_PASSWORD must equal POSTGRES_PASSWORD.
# Kept out of the compose YAML so the placeholder filler never sees them.
cat > "$STORE/app.env" <<EOF
SPRING_DATASOURCE_PASSWORD=${DB_PASSWORD}
TOLGEE_AUTHENTICATION_JWT_SECRET=${JWT_SECRET}
TOLGEE_AUTHENTICATION_INITIAL_PASSWORD=${ADMIN_PASSWORD}
EOF
chmod 600 "$STORE/db.env" "$STORE/app.env"

# Record the admin login for the operator (idempotent).
cat > "$STORE/credentials.txt" <<EOF
Tolgee admin login
  username: admin
  password: ${ADMIN_PASSWORD}
EOF
chmod 600 "$STORE/credentials.txt"

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull tolgee/tolgee:v3.224.5 || true
docker pull postgres:17-alpine || true
