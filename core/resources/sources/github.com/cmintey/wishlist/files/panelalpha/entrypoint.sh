#!/bin/sh
# Upstream's own entrypoint.sh with one line added, mounted over it by
# overrides/docker-compose.yml.
#
# The added line is panelalpha-install.mjs, and it runs here rather than in a
# sibling compose service because of what it does: it creates the account and
# turns off public signup, and until it has, the first visitor to reach
# /setup-wizard owns the installation. A one-shot beside the app can only
# start once the app is healthy -- which is once the app is answering -- so
# there would be a window, however short, on a public HTTPS name. Nothing is
# listening on 3000 until the last line of this file.
#
# Everything above it is upstream's, kept deliberately byte-for-byte in
# meaning: the two proxy headers, the database path the image's VOLUME points
# at, the currency the client bundle reads, the body-size limit, Caddy in the
# background, and migrate/seed/patch chained with && exactly as upstream
# chains them. ORIGIN is not set here -- it comes from the compose
# environment, where the engine can rewrite it to the account's public URL.
export PROTOCOL_HEADER=x-forwarded-proto
export HOST_HEADER=x-forwarded-host
export DATABASE_URL="file:/usr/src/app/data/prod.db"
export PUBLIC_DEFAULT_CURRENCY=${DEFAULT_CURRENCY}
export BODY_SIZE_LIMIT=${MAX_IMAGE_SIZE:-5000000}

caddy start --config /usr/src/app/Caddyfile

pnpm prisma migrate deploy && \
pnpm prisma db seed && \
pnpm db:patch

# Idempotent, and always exits 0 -- see the file. It needs the schema, the
# roles and the Default group, so it runs after the three commands above and
# before the server.
node /usr/src/app/panelalpha-install.mjs

exec pnpm start
