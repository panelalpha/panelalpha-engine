#!/bin/bash
set -e
cd ~/project

# Everything overrides/docker-compose.yml interpolates. Guarded as a whole: the
# MariaDB data volume and the settings row that records "setup completed"
# outlive the checkout, so regenerating either the password or APP_KEY on a
# redeploy would lock LinkAce out of its own database or invalidate every
# session and encrypted column it has written.
if [ -f .env ]; then
    exit 0
fi

# The image tag comes from the checkout rather than from a constant, because
# linkace/linkace publishes a tag per major branch (1.x, 2.x) and a clone of
# 1.x must not get 2.x code against a 1.x database. package.json is the only
# file in the repository carrying the product version ("version": "2.6.2");
# config/app.php has an api_version and no app version at all. Anything
# unreadable falls back to 2.x -- the repository's default branch -- rather
# than to :latest, which would silently move a checkout across a major.
LINKACE_MAJOR=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9]\{1,\}\)\..*/\1/p' package.json | head -1)
case "${LINKACE_MAJOR}" in
    ''|*[!0-9]*) LINKACE_MAJOR=2 ;;
esac

# base64:<32 raw bytes>, the only shape Laravel's Encrypter accepts for
# AES-256-CBC. Not hex and not a bare string: config/app.php reads APP_KEY
# verbatim and the cipher check happens at boot, so a wrong-length key is a 500
# on every page that touches a session.
cat > .env <<EOF
LINKACE_IMAGE=linkace/linkace:${LINKACE_MAJOR}.x
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF
