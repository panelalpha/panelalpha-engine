#!/bin/bash
set -e
cd ~/project

# Digiboard's base docker-compose.yml is read by the engine for its redis
# sidecar (the app service itself is dropped: it is what the generated
# compose builds). The repository also ships docker-compose.override.yml for
# local test — COOKIE_SECURE=0, DOMAIN=http://localhost:3000, app published on
# 3000, traefik behind a profile — and the engine merges any override still
# sitting in the tree over the compose file it generated. Its
# DOMAIN=http://localhost:3000 would then outrank the account's own
# DOMAIN, and every link and websocket the app generates would point at
# localhost. Stashing it leaves the engine's generated file as the only
# definition.
if [ -f docker-compose.override.yml ] && [ ! -f docker-compose.override.yml.panelalpha-local ]; then
    mv docker-compose.override.yml docker-compose.override.yml.panelalpha-local
fi

# The server refuses to start in production without SESSION_KEY, and the redis
# sidecar's password is DB_PWD. Both are generated once into ~/.panelalpha and
# added to .env (which compose reads for the app and interpolates for redis).
SECRETS="$HOME/.panelalpha/digiboard"
mkdir -p "$SECRETS"
if [ ! -s "$SECRETS/secrets.env" ]; then
    ( umask 077; printf 'DB_PWD=%s\nSESSION_KEY=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 32)" > "$SECRETS/secrets.env" )
fi
touch .env
while IFS= read -r line; do
    grep -q "^${line%%=*}=" .env || printf '%s\n' "$line" >> .env
done < "$SECRETS/secrets.env"
