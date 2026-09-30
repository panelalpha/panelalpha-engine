#!/bin/bash
set -e
# INSTATIC_SECRET_KEY encrypts AI credentials, plugin secrets and TOTP seeds:
# generated once and reused, since ~/project is wiped on every deploy and a new
# key makes the stored ones unreadable.
DIR="$HOME/.panelalpha/instatic"
ENV="$DIR/secrets.env"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
if [ ! -f "$ENV" ]; then
    ( umask 077; echo "INSTATIC_SECRET_KEY=$(openssl rand -base64 32)" > "$ENV" )
fi
chmod 600 "$ENV"
