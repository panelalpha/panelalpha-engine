#!/bin/bash
# Runs on the account after the clone, before detection.
#
# What a Wishlist checkout cannot say: where the wish lists live.
#
# Upstream's compose file bind-mounts ./data and ./uploads -- inside the
# checkout. A redeploy clears and re-clones ~/project, so every
# list, item, claim and uploaded image would be destroyed by the next deploy.
# They live in
# ~/.panelalpha/wishlist instead; ~ is root-owned 0755 and nothing can be
# created there, while ~/.panelalpha is created with the account and belongs to
# it.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/wishlist"

say() { echo "[panelalpha] wishlist: $*"; }

mkdir -p "${DATA_HOME}/data" "${DATA_HOME}/uploads"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# The account
# ---------------------------------------------------------------------------
# Wishlist ships no installer and no seeded user, so files/panelalpha/install.mjs
# creates the admin from the login the engine generated (`credentials:` in
# panelalpha.yaml), which the engine has written to
# ~/.panelalpha/app-credentials.env before this hook runs; compose binds that
# file read-only into the container. Nothing to generate here any more. An
# installation this hook seeded before the engine owned the login keeps its
# password in ~/.panelalpha/wishlist/credentials, which `adopt_from` reads.

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------
# Written here so that ProjectEnvironment::apply() finds a real .env and keeps
# it, instead of copying .env.example -- which is the whole of why the stock
# deploy failed. That file ships `ORIGIN=` with no value, compose loads it
# through `env_file:`, and ORIGIN then reaches the container *set and empty*.
# @sveltejs/adapter-node validates it:
#
#     Error: Invalid ORIGIN: ''. ORIGIN must be a valid URL with http:// or
#     https:// protocol.
#
# `node build` exits 1, the container restart-loops, and the proxy answers 502.
# ORIGIN is deliberately absent from this file: the compose `environment:`
# block carries it, because only the engine knows the account's public address.
#
# 0644, the mode the engine writes its own .env with. Nothing in it is secret
# -- the password is in ~/.panelalpha/app-credentials.env -- and a 0600 .env
# is invisible to EnvSidecars::variableMap(), which reads it with plain
# file_get_contents() as www-data and silently falls back to .env.example,
# putting `ORIGIN=` right back.
cat > .env <<'EOF'
# Written by PanelAlpha. The admin login for this installation is the one the
# engine generated (GET /projects/{name}/app-credentials).
#
# ORIGIN is not here: it is set in docker-compose.yml to this account's public
# address, because a compose `environment:` value outranks `env_file:` and the
# checkout has no way to know the address. Edit it there if you serve Wishlist
# under a different name. Leaving it empty is not an option -- adapter-node
# rejects an empty ORIGIN and the server will not start.

# Hours until signup and password reset tokens expire
TOKEN_TIME=72
# The currency to use when a product search does not return a currency
DEFAULT_CURRENCY=USD
# trace | debug | info | warn | error | fatal | silent
LOG_LEVEL=info
# Maximum image size that can be uploaded, in bytes
MAX_IMAGE_SIZE=5000000
EOF
chmod 644 .env

say "prepared; data and uploads live in ${DATA_HOME}"
