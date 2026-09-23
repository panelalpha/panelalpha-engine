#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml has been
# written, before detection and the build.
#
# Three things the compose file cannot do for itself: pin the image tag from the
# checkout, put this account's secrets somewhere the next clone will not delete,
# and tell the token container which uid it has to be in order to write there.
set -e
cd ~/project

say() { echo "[svix] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL, and a fork or a mirror answers to the same
# one. Everything below assumes the svix/svix-webhooks layout, and the compose
# file runs a published image that has to match the checkout it was pinned from.
# Saying so here turns a confusing runtime failure into one line in the deploy
# log.
if [ ! -f server/Cargo.toml ]; then
    say "WARNING: server/Cargo.toml is missing; this recipe expects the svix/svix-webhooks layout"
fi

# ---------------------------------------------------------------------------
# 1. The image tag, from the checkout.
#
# server/Cargo.toml's [workspace.package] is the one real version string here:
# `version = "1.101.0"`, and Docker Hub publishes it as `v1.101.0`. The `^`
# anchor is what keeps the `version = "=1.11.1"` inside the hyper dependency
# table out of the answer -- that line starts with `hyper`, this one with
# `version`.
#
# Confirmed published before it is used, because a tag whose pull fails takes
# the whole deploy with it. A clone of main between releases carries a version
# with no image yet, and falls back to :latest rather than to a 404.
SVIX_TAG=latest
SVIX_VERSION=$(sed -n 's/^version[[:space:]]*=[[:space:]]*"\([0-9][^"]*\)".*/\1/p' server/Cargo.toml 2>/dev/null | head -1)
if [ -n "${SVIX_VERSION}" ] && curl -fsS --max-time 15 -o /dev/null \
    "https://hub.docker.com/v2/repositories/svix/svix-server/tags/v${SVIX_VERSION}" 2>/dev/null; then
    SVIX_TAG="v${SVIX_VERSION}"
fi
say "using svix/svix-server:${SVIX_TAG}"

# ---------------------------------------------------------------------------
# 2. The secrets, outside the checkout.
#
# engine#173: GitRepository::cloneConfiguredRepository() empties ~/project on
# every deploy, so a guard on a file in there never fires and a regenerated
# secret is a silent data loss. And ProjectEnvironment::apply() copies ~/project
# /.env to .env.default at mode 644, so nothing secret may be written there
# either. Both rule out the usual `if [ -f .env ]; then exit 0; fi` shape.
#
# Everything generated here therefore lives in ~/.panelalpha/, which survives
# the clone, at 0600 in a 0700 directory. The account's home is root-owned 0755,
# so the directory has to be created rather than assumed.
#
# What is at stake if any of it is regenerated:
#
#   SVIX_JWT_SECRET   signs every API token. Rotating it invalidates the token
#                     in ~/.panelalpha/svix/admin-token and every token the
#                     customer has issued from it or embedded in an integration.
#   SVIX_MAIN_SECRET  encrypts endpoint signing secrets at rest. cfg.rs's own
#                     comment is "IMPORTANT: Once set, it can't be changed" --
#                     rotating it makes every stored whsec_ key undecryptable.
#   POSTGRES_PASSWORD is in the volume's pg_authid, not just in this file.
STORE_DIR="${HOME}/.panelalpha"
STORE="${STORE_DIR}/svix.env"
DB_STORE="${STORE_DIR}/svix-db.env"
TOKEN_DIR="${STORE_DIR}/svix"
TOKEN_FILE="${TOKEN_DIR}/admin-token"

mkdir -p "${STORE_DIR}" "${TOKEN_DIR}"
chmod 700 "${STORE_DIR}" "${TOKEN_DIR}"

if [ ! -f "${STORE}" ] || [ ! -f "${DB_STORE}" ]; then
    # Hex rather than base64 throughout: SVIX_DB_DSN carries the password as URL
    # userinfo, and a '/', '@' or ':' in it re-parses the connection string
    # around the wrong character.
    PG_PASSWORD=$(openssl rand -hex 24)
    (
        umask 077
        cat > "${DB_STORE}" <<EOF
# Written by PanelAlpha on the first deploy. Read by the postgres service as a
# second env_file; see overrides/docker-compose.yml. The password is also in the
# postgres_data volume -- changing it here alone locks Svix out of its database.
POSTGRES_USER=svix
POSTGRES_PASSWORD=${PG_PASSWORD}
POSTGRES_DB=svix
EOF
        cat > "${STORE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated: these are
# the account's Svix secrets and they outlive the checkout in ~/project, which
# every deploy empties.
#
# Signs every API token. Svix refuses to start without it (there is no default),
# and rotating it invalidates every token ever issued by this instance.
SVIX_JWT_SECRET=$(openssl rand -hex 32)
# Encrypts endpoint signing secrets at rest. Left unset, Svix's Encryption type
# defaults to new_noop() and every whsec_ key is stored in Postgres in the clear.
# Svix's own note is "IMPORTANT: Once set, it can't be changed".
SVIX_MAIN_SECRET=$(openssl rand -hex 32)
SVIX_DB_DSN=postgresql://svix:${PG_PASSWORD}@postgres:5432/svix
SVIX_REDIS_DSN=redis://redis:6379
# Uncomment to let this instance deliver webhooks to private address space.
# Left unset, Svix blocks those endpoints, which is what stops a webhook URL
# from becoming an SSRF into the host's own network.
# SVIX_WHITELIST_SUBNETS=[172.16.0.0/12]
EOF
    )
    chmod 600 "${STORE}" "${DB_STORE}"
    say "generated this account's JWT signing secret, main secret and database password in ~/.panelalpha/"
else
    say "reusing the secrets already in ~/.panelalpha/svix.env"
fi

# The token container writes into this file rather than creating it, so that a
# truncating redirect keeps the owner and the 0600 the file is created with here.
if [ ! -f "${TOKEN_FILE}" ]; then
    (umask 077; : > "${TOKEN_FILE}")
fi
chmod 600 "${TOKEN_FILE}"

# ---------------------------------------------------------------------------
# 3. ~/project/.env -- the file compose interpolates, and nothing else.
#
# Rewritten on every deploy rather than guarded, because everything in it is
# derived and none of it is secret: the engine republishing this file as a
# world-readable .env.default is then a non-event. The secrets reach the
# containers through the second env_file instead.
#
# SVIX_RUN_AS is what lets the token container write into ~/.panelalpha/svix:
# the image runs as uid 1001, the directory belongs to this account, and a bind
# mount matches on the number.
cat > .env <<EOF
# Written by PanelAlpha. Compose reads this for \${...} substitution in
# docker-compose.yml. Nothing secret belongs here: the engine copies this file
# to .env.default at mode 644 (engine#173). This account's Svix secrets are in
# ~/.panelalpha/svix.env and ~/.panelalpha/svix-db.env, both 0600.
SVIX_IMAGE=svix/svix-server:${SVIX_TAG}
POSTGRES_IMAGE=postgres:16-alpine
REDIS_IMAGE=redis:7-alpine
SVIX_RUN_AS=$(id -u):$(id -g)
EOF
chmod 600 .env

# ---------------------------------------------------------------------------
# 4. Where a human is pointed.
#
# Not in ~/project: it would be deleted by the next deploy, and the token it
# names would not. Kept beside the secrets it describes.
cat > "${STORE_DIR}/svix-credentials.txt" <<EOF
Svix (svix/svix-webhooks), deployed by PanelAlpha.

Svix is an API, not a web interface. It has no users, no sign-up page and no
setup wizard: every request carries a bearer token, and there is no way to get
one over the network. Opening the site in a browser shows the API reference,
which is what / redirects to.

  API base            https://<your domain>/api/v1
  API reference       https://<your domain>/docs
  OpenAPI document    https://<your domain>/api/v1/openapi.json
  Health              https://<your domain>/api/v1/health

  Your API token      ~/.panelalpha/svix/admin-token   (0600)

Use it as an ordinary bearer token:

  curl -H "Authorization: Bearer \$(cat ~/.panelalpha/svix/admin-token)" \\
       https://<your domain>/api/v1/app

The token is valid for ten years. To issue another, or one scoped to a
different organisation:

  cd ~/project && docker compose run --rm --no-deps token svix-server jwt generate

Secrets, none of which can be rotated without losing data:

  ~/.panelalpha/svix.env        SVIX_JWT_SECRET signs every token; changing it
                                invalidates all of them. SVIX_MAIN_SECRET
                                encrypts endpoint signing secrets at rest and
                                cannot be changed once data exists.
  ~/.panelalpha/svix-db.env     the Postgres password, which is also inside the
                                postgres_data volume.

Delivery to private IP addresses is blocked. An endpoint whose URL resolves
into private address space records its attempts as failed with "requests to
this IP range are blocked". To allow it anyway, set SVIX_WHITELIST_SUBNETS in
~/.panelalpha/svix.env and redeploy -- and understand that you are allowing
this instance to make requests into the network it is hosted on.

The consumer-facing App Portal is not part of the open-source server: the
dashboard-access endpoint returns a link to app.svix.com, which this instance
is not. Everything else in the API reference is served from here.
EOF
chmod 600 "${STORE_DIR}/svix-credentials.txt"

say "credentials and notes in ~/.panelalpha/svix-credentials.txt"
