#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml has been
# written into ~/project, before detection and the build.
#
# Nothing here is built. docker/Dockerfile's payload is a release .deb
# (`COPY ${PACKAGE}_${TARGETARCH}.deb /`) that is produced elsewhere and is not
# in the checkout, so the tree cannot build its own image; upstream publishes
# sutoj/piler on Docker Hub instead. What this hook supplies is the image tag,
# taken from the checkout, and this account's credentials, kept where the next
# clone will not delete them.
set -e
cd ~/project

say() { echo "[piler] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one. The
# compose file below runs a published image pinned from VERSION, and the setup
# service matches password hashes that come from util/db-mysql.sql. Saying so
# here turns a puzzling runtime failure into one line in the deploy log.
if [ ! -f VERSION ] || [ ! -d webui ] || [ ! -f docker/docker-compose.yaml ]; then
    say "WARNING: this does not look like the jsuto/piler layout (VERSION, webui/, docker/ expected)"
fi

# ---------------------------------------------------------------------------
# 1. The image tag, from the checkout.
#
# VERSION is a single line, `1.4.9`, and is the one version string in the tree
# that means the product (configure.in reads it too). Docker Hub publishes it
# unprefixed -- 1.4.9 was pushed 2026-06-17 -- but only for releases, so a clone
# of master between two of them carries a version with no image yet.
#
# Confirmed published before it is used, because a tag whose pull fails takes
# the whole deploy with it. The fallback is `latest`, which upstream moves with
# each release.
PILER_VERSION=$(tr -d '[:space:]' < VERSION 2>/dev/null)
PILER_TAG=latest
if [ -n "${PILER_VERSION}" ] && curl -fsS --max-time 15 -o /dev/null \
    "https://hub.docker.com/v2/repositories/sutoj/piler/tags/${PILER_VERSION}" 2>/dev/null; then
    PILER_TAG="${PILER_VERSION}"
fi
say "using sutoj/piler:${PILER_TAG}"

# ---------------------------------------------------------------------------
# 2. The secrets, outside the checkout.
#
# Every deploy empties ~/project before the clone, so a guard on a
# file in there never fires on a redeploy -- the database password would be
# regenerated while db_data still held the old one. And ProjectEnvironment::apply() copies ~/project/.env to .env.default at
# mode 644 inside a home that is root-owned 0755, which makes anything written
# there readable by every other account's uid on this host.
#
# So everything generated here lives in ~/.panelalpha/ at 0600 in a 0700
# directory, and that file is also the stack's second env_file -- compose reads
# it at ../.panelalpha/piler.env, relative to the --project-directory the engine
# passes. No secret is ever written into ~/project. The web UI administrator's
# login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha"
STORE="${STORE_DIR}/piler.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${STORE}" ]; then
    # Alphanumeric, no punctuation: MYSQL_PASSWORD is sed'd into piler.conf and
    # into config-site.php as a single-quoted PHP literal by the image's
    # start.sh (`s/verystrongpassword/${MYSQL_PASSWORD}/g`), where a '/' would
    # end the sed expression and a quote would end the literal.
    umask 077
    cat > "${STORE}" <<EOF
# Written by PanelAlpha on the first deploy of this account, and reused by every
# redeploy. This file is the compose stack's env_file; nothing in it is ever
# copied into ~/project, because the engine republishes ~/project/.env as a
# world-readable .env.default.
#
# The database account Piler connects with. Read by the mariadb image to create
# it, and by the piler image's start.sh to write piler.conf, config-site.php and
# /etc/piler/.my.cnf. The password is also inside the db_data volume, so
# changing it here alone locks Piler out of its own archive.
MYSQL_DATABASE=piler
MYSQL_USER=piler
MYSQL_PASSWORD=$(openssl rand -hex 24)
EOF
    chmod 600 "${STORE}"
    say "generated this account's database credentials in ~/.panelalpha/piler.env"
else
    say "reusing the credentials already in ~/.panelalpha/piler.env"
fi

# ---------------------------------------------------------------------------
# 3. ~/project/.env -- the file compose interpolates, and nothing else.
#
# Rewritten on every deploy rather than guarded, because everything in it is
# derived and none of it is secret, so the engine republishing it at 644 is a
# non-event.
cat > .env <<EOF
# Written by PanelAlpha. Compose reads this for \${...} substitution in
# docker-compose.yml. Nothing secret belongs here: the engine copies this file
# to .env.default at mode 644. This account's credentials are in
# ~/.panelalpha/piler.env, 0600.
PILER_IMAGE=sutoj/piler:${PILER_TAG}
PILER_DB_IMAGE=mariadb:12.0.2
PILER_MANTICORE_IMAGE=manticoresearch/manticore:25.0.0
PILER_MEMCACHED_IMAGE=memcached:1.6-alpine
EOF
chmod 600 .env
