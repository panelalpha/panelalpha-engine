#!/bin/bash
set -e
# Generated once and reused: ~/project is wiped on every deploy, and a new
# session secret logs everyone out, a new DB password locks out the volume.
DIR="$HOME/.panelalpha/monitorss"
ENV="$DIR/secrets.env"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
if [ ! -f "$ENV" ]; then
    PG=$(openssl rand -hex 16)
    ( umask 077
      cat > "$ENV" <<EOT
POSTGRES_PASSWORD=${PG}
FEED_REQUESTS_POSTGRES_URI=postgres://postgres:${PG}@feed-requests-postgres-db:5432/feedrequests
USER_FEEDS_POSTGRES_URI=postgres://postgres:${PG}@feed-requests-postgres-db:5432/userfeeds
BACKEND_API_SESSION_SECRET=$(openssl rand -hex 32)
BACKEND_API_SESSION_SALT=$(openssl rand -hex 8)
EOT
    )
fi
chmod 600 "$ENV"
