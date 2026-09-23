#!/bin/bash
# Generate the admin password and JWT secret ONCE into the only writable
# rebuild-surviving directory (~/.panelalpha), reuse them on every redeploy,
# and expose them to the compose file via ~/project/.env. ~/project is wiped
# each redeploy; ~/.panelalpha is not, so the admin login keeps working over
# the persisted SQLite database.
set -e

SECRETS_DIR=~/.panelalpha/shipshipship
SECRETS_FILE="$SECRETS_DIR/secrets.env"

mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"

if [ ! -f "$SECRETS_FILE" ]; then
  umask 077
  cat > "$SECRETS_FILE" <<EOF
SSS_ADMIN_USERNAME=admin
SSS_ADMIN_PASSWORD=$(openssl rand -hex 16)
SSS_JWT_SECRET=$(openssl rand -hex 32)
EOF
  chmod 600 "$SECRETS_FILE"
fi

# Re-materialise the env the compose interpolates from the persisted secrets.
cp "$SECRETS_FILE" ~/project/.env
chmod 600 ~/project/.env
