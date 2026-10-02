#!/bin/bash
set -e
cd ~/project

# The engine reads runtime services from a compose file that looks like a
# local-dev stack; Digibuzzer's ships neither a repo bind-mount nor host uid
# build args, so the reader skips it and this recipe layers the redis sidecar
# in through overrides/docker-compose.override.yml — which AppConfigBootstrap
# has ALREADY written to ./docker-compose.override.yml by the time this hook
# runs. Only the repository's own local-test override (COOKIE_SECURE=0,
# DOMAIN=http://localhost:3000, app published on 3000, traefik behind a
# profile) must be moved aside: merged over the generated compose its
# DOMAIN=http://localhost:3000 outranks the account's own DOMAIN and every
# link and websocket the app generates would point at localhost. Recognise it
# by that DOMAIN value so the recipe's own layer, written moments before, is
# left where Compose picks it up.
if [ -f docker-compose.override.yml ] && grep -q 'DOMAIN=http://localhost' docker-compose.override.yml; then
    if [ ! -f docker-compose.override.yml.panelalpha-local ]; then
        mv docker-compose.override.yml docker-compose.override.yml.panelalpha-local
    else
        mv -f docker-compose.override.yml docker-compose.override.yml.repo-local
    fi
fi

# The server refuses to start in production without SESSION_KEY, and the redis
# sidecar's password is DB_PWD. Both are generated once into ~/.panelalpha and
# added to .env (which compose reads for the app and interpolates for redis).
SECRETS="$HOME/.panelalpha/digibuzzer"
mkdir -p "$SECRETS"
if [ ! -s "$SECRETS/secrets.env" ]; then
    ( umask 077; printf 'DB_PWD=%s\nSESSION_KEY=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 32)" > "$SECRETS/secrets.env" )
fi
touch .env
while IFS= read -r line; do
    grep -q "^${line%%=*}=" .env || printf '%s\n' "$line" >> .env
done < "$SECRETS/secrets.env"
