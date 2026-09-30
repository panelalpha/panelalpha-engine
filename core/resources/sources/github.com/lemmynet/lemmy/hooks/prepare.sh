#!/bin/bash
set -e
# The Postgres password and the pict-rs API key are generated once and reused:
# ~/project is wiped on every deploy, and a new password would lock Lemmy out
# of its pgdata volume.
SECRET_DIR="$HOME/.panelalpha/lemmy"
SECRETS="$SECRET_DIR/secrets.env"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true
if [ ! -f "$SECRETS" ]; then
    ( umask 077
      { printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'PICTRS__SERVER__API_KEY=%s\n' "$(openssl rand -hex 24)"; } > "$SECRETS" )
fi
chmod 600 "$SECRETS"
