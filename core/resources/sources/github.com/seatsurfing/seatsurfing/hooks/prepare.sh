#!/bin/bash
# Generate Seatsurfing's secrets once and reuse them across redeploys.
# ~/project is wiped every redeploy; ~/.panelalpha survives, so the secrets
# live there (0600) and are only generated on the first deploy. Re-applying
# the same CRYPT_KEY is mandatory: it decrypts existing DB columns.
set -e

SECRET_DIR="$HOME/.panelalpha/seatsurfing"
mkdir -p "$SECRET_DIR"
chmod 700 "$SECRET_DIR"

# gen_or_read <file> <generator-cmd>: create with the generator if missing, then print.
gen_or_read() {
    local file="$1"; shift
    if [ ! -s "$file" ]; then
        "$@" > "$file"
        chmod 600 "$file"
    fi
    cat "$file"
}

# 32 hex chars = exactly 32 bytes, which is what CRYPT_KEY requires.
DB_PASSWORD=$(gen_or_read "$SECRET_DIR/db_password" openssl rand -hex 16)
CRYPT_KEY=$(gen_or_read "$SECRET_DIR/crypt_key" openssl rand -hex 16)
INIT_ORG_PASS=$(gen_or_read "$SECRET_DIR/admin_password" openssl rand -hex 12)

# Record the admin login for the operator (idempotent).
echo "admin@seatsurfing.local" > "$SECRET_DIR/admin_email"
chmod 600 "$SECRET_DIR/admin_email"

# Docker Compose reads .env in the project dir for ${VAR} interpolation.
cat > .env <<EOF
DB_PASSWORD=${DB_PASSWORD}
CRYPT_KEY=${CRYPT_KEY}
INIT_ORG_PASS=${INIT_ORG_PASS}
EOF
chmod 600 .env

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull ghcr.io/seatsurfing/backend:1.127.9 || true
docker pull postgres:18 || true
