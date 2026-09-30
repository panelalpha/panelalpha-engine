#!/bin/bash
# SHELLY_SECRET_KEY encrypts the device credentials stored in the data volume,
# so it is generated once into ~/.panelalpha (~/project is wiped on every deploy).
set -e
STORE="${HOME}/.panelalpha/shelly-manager"
mkdir -p "$STORE"
chmod 700 "${HOME}/.panelalpha" "$STORE"
if [ ! -f "$STORE/app.env" ]; then
    ( umask 077
      printf 'SHELLY_SECRET_KEY=%s\n' "$(openssl rand -base64 32 | tr '+/' '-_')" > "$STORE/app.env" )
fi
touch ~/project/.env
