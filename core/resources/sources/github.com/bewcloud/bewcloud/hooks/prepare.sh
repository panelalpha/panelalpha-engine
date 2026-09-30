#!/bin/bash
# Secrets generated ONCE into ~/.panelalpha/bewcloud (~/project is wiped every
# deploy): a new DB password would lock the app out of its volume, a new
# JWT_SECRET/PASSWORD_SALT logs everyone out or breaks every password.
set -e
DIR="$HOME/.panelalpha/bewcloud"
ENV="$DIR/app.env"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
if [ ! -f "$ENV" ]; then
    DB_PASSWORD=$(openssl rand -hex 24)
    ( umask 077
      cat > "$ENV" <<E
POSTGRES_PASSWORD=${DB_PASSWORD}
POSTGRESQL_PASSWORD=${DB_PASSWORD}
JWT_SECRET=$(openssl rand -hex 32)
PASSWORD_SALT=$(openssl rand -hex 32)
MFA_KEY=$(openssl rand -hex 32)
MFA_SALT=$(openssl rand -hex 32)
E
    )
    chmod 600 "$ENV"
fi
