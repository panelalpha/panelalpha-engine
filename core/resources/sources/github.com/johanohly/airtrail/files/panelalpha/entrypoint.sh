#!/bin/sh
# Wraps the upstream boot sequence (docker/entrypoint.sh) with one extra step:
# seed the owner between the migrations and the server, so AirTrail's open setup
# window is already closed the first time the public can reach it. WORKDIR is
# /app (the image default), so the relative paths below match the upstream image.
set -e

echo "[panelalpha] Applying migrations..."
node ./docker/migrate.js

echo "[panelalpha] Seeding owner (idempotent)..."
node /app/panelalpha-seed-owner.mjs

echo "[panelalpha] Starting server..."
# Trust the engine's reverse proxy for scheme/host, exactly as upstream does.
export PROTOCOL_HEADER=x-forwarded-proto
export HOST_HEADER=x-forwarded-host
exec node ./build
