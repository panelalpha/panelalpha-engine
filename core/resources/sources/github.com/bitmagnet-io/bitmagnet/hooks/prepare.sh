#!/bin/bash
set -e
# The Postgres password is generated once and reused: ~/project is wiped on
# every deploy, and a new password would lock the app out of the pgdata volume.
SECRET_DIR="$HOME/.panelalpha/bitmagnet"
DB_ENV="$SECRET_DIR/db.env"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true
if [ ! -f "$DB_ENV" ]; then
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "$DB_ENV" )
fi
chmod 600 "$DB_ENV"
