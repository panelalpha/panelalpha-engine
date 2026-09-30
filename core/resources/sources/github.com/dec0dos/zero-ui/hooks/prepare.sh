#!/bin/bash
# Admin password for zero-ui's first run, generated once; ~/project is wiped per deploy.
set -e
DIR="$HOME/.panelalpha/zero-ui"
mkdir -p "$DIR"
chmod 700 "$HOME/.panelalpha" "$DIR" 2>/dev/null || true
if [ ! -s "$DIR/zero-ui.env" ]; then
    ( umask 077; echo "ZU_DEFAULT_PASSWORD=$(openssl rand -hex 16)" > "$DIR/zero-ui.env" )
fi
chmod 600 "$DIR/zero-ui.env"
