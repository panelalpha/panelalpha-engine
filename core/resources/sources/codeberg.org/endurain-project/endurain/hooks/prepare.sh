#!/bin/bash
set -e
cd ~/project

# Endurain ships its production stack only as templates: docker-compose.yml.example
# (pinned postgres:18 + redis:8 images, host bind mounts under /var/opt/endurain),
# docker-compose.yml.secrets.example and docker-compose.yml-multiple-backends.example.
# The engine reads the example for its runtime sidecars — but with credentials of
# its own choosing, which the app's generated DB_PASSWORD would never match — and
# the compose probe would decline the file for its prebuilt app image anyway.
# Stash all three under names the engine neither runs nor reads sidecars from;
# the recipe's overrides/docker-compose.override.yml is the stack instead.
for f in docker-compose.yml.example docker-compose.yml.secrets.example docker-compose.yml-multiple-backends.example; do
    if [ -f "$f" ]; then
        mv -f "$f" "$f.panelalpha-off"
    fi
done

# .env from the example the repo bundles, with the secrets it publishes as
# changeme generated for this account. DB_PASSWORD and POSTGRES_PASSWORD must
# be the same value: the backend reads the first, the postgres image the second.
# SECRET_KEY signs the JWTs. FERNET_KEY has a format requirement — 32 bytes,
# urlsafe-base64 — which is what `openssl rand -base64 32` becomes after the
# +/ → -_ transliteration; a plain changeme is rejected at startup.
DB_PASSWORD=$(openssl rand -hex 16)
SECRET_KEY=$(openssl rand -hex 32)
FERNET_KEY=$(openssl rand -base64 32 | tr '+/' '-_' | tr -d '\n')

cp .env.example .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${DB_PASSWORD}|" .env
sed -i "s|^SECRET_KEY=.*|SECRET_KEY=${SECRET_KEY}|" .env
sed -i "s|^FERNET_KEY=.*|FERNET_KEY=${FERNET_KEY}|" .env
# ENDURAIN_HOST is required at startup (backend/app/core/config.py refuses to
# boot without it) and drives three things: CORS allow-origins, the SPA's
# runtime env.js, and the CSP connect-src — all rewritten by start.sh on every
# boot from this one value. The engine's public-URL aliases carry it into the
# container pointing at the real domain, so a same-origin fetch works and the
# CSP the SPA pins is its own origin. The hook leaves the example's value in
# place only until the engine's compose environment replaces it.
sed -i "s|^ENDURAIN_HOST=.*|ENDURAIN_HOST=https://endurain.example.invalid|" .env

# The example's bind mounts default to /var/opt/endurain, which only root can
# create inside the account and the hardener refuses. ~/project is emptied on
# every deploy, so the data goes to ~/.panelalpha, the one writable directory
# a redeploy keeps. Point LOCAL_PATH there — compose interpolates it from .env —
# and create the tree the app's start.sh demands, owned by the account user,
# which is the uid the container runs as. Pre-creating matters: compose would
# create missing bind sources as root and the container's non-root user could
# write nothing.
DATA_DIR="${HOME}/.panelalpha/endurain"
mkdir -p "${DATA_DIR}/backend/data" "${DATA_DIR}/backend/logs" "${DATA_DIR}/postgres" "${DATA_DIR}/redis"
grep -q "^LOCAL_PATH=" .env || printf '\nLOCAL_PATH=%s\n' "${DATA_DIR}" >> .env
# The image bakes in UID/GID 1000, but the data directories just created belong
# to this account's own uid — 1001 on the first account of a host — so the app
# restart-looped on "The bind mount on the host is not writable by container
# UID 1000". Record the uid; the recipe's compose override runs the app
# container as it.
grep -q "^CONTAINER_UID=" .env || printf '\nCONTAINER_UID=%s\n' "$(id -u)" >> .env