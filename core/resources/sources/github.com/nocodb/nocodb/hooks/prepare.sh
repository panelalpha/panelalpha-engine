#!/bin/bash
set -e
cd ~/project

# Guarded as a whole: the nocodb_data volume outlives the checkout, so a
# regenerated JWT secret would invalidate every session, a regenerated
# NC_CONNECTION_ENCRYPT_KEY would make every stored data-source credential
# undecryptable (the helm chart's own words: "never rotated").
if [ -f .env ]; then
    exit 0
fi

# The tag comes from the checkout rather than from a constant: nocodb/nocodb
# has no major-branch tag to pin to (the product is still 0.x, so "0" would
# mean nothing), only :latest and one tag per release. packages/nocodb/package.json
# is the server's own manifest and its "version" is the second key in the file.
#
# develop carries the *next* version, which may not be published yet, so the
# tag is confirmed against the registry before it is used. Anything unreadable,
# unpublished or unreachable falls back to :latest rather than to a tag that
# would fail the pull and take the whole deploy with it.
NOCODB_VERSION=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9][0-9A-Za-z.-]*\)".*/\1/p' packages/nocodb/package.json | head -1)
NOCODB_TAG=latest
if [ -n "${NOCODB_VERSION}" ] \
    && curl -fsS --max-time 15 -o /dev/null \
        "https://hub.docker.com/v2/repositories/nocodb/nocodb/tags/${NOCODB_VERSION}" 2>/dev/null; then
    NOCODB_TAG="${NOCODB_VERSION}"
fi

# NC_ADMIN_EMAIL / NC_ADMIN_PASSWORD (initAdminFromEnv.ts creates the super
# admin from them) are the engine's (`credentials:` in panelalpha.yaml); the
# services read them from ~/.panelalpha/app-credentials.env.

cat > .env <<EOF
NOCODB_IMAGE=nocodb/nocodb:${NOCODB_TAG}
# Noco.ts generates a uuidv4 into nc_store when this is unset. Set here so the
# signing key is 32 random bytes rather than 122 bits of a v4 UUID, and so it
# is recorded outside the database.
NC_AUTH_JWT_SECRET=$(openssl rand -hex 32)
# encryptDecrypt.ts: with this set, external data-source credentials are stored
# AES-encrypted instead of as plaintext JSON in nc_sources.
NC_CONNECTION_ENCRYPT_KEY=$(openssl rand -hex 32)
EOF
chmod 600 .env
