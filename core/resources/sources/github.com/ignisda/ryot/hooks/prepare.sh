#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml and
# files/ are in place, before `docker compose up`. Ryot is a Rust backend + a
# React-Router (Node) frontend behind Caddy, all in one published image, with
# PostgreSQL as an external datastore. This hook only mints and persists the
# secrets the stack needs; the compose file wires them in and an init one-shot
# claims the owner.
set -e

say() { echo "[ryot] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The DB password, the admin access token and the owner password
# must live here and stay stable: on a redeploy the role already exists in the
# pgdata volume with the old password, and the owner already exists in the DB,
# so regenerating any of these would lock the app out or orphan the account.
STORE_DIR="${HOME}/.panelalpha/ryot"
ENV_FILE="${STORE_DIR}/ryot.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_FILE}" ]; then
    OWNER_USER=admin
    # No '/', '+', '=' or ':' -- these values are read back by a POSIX shell,
    # embedded in a JSON GraphQL body without escaping, and one of them goes
    # inside a postgres:// URL where ':' and '@' are delimiters.
    OWNER_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
    PG_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
    # Admin access token: gates registerUser when registration is disabled, so
    # only the init one-shot can create users. hex so it embeds cleanly in JSON.
    ADMIN_ACCESS_TOKEN="$(openssl rand -hex 24)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated. Deleting
# this file does not reset the application: POSTGRES_PASSWORD is already stored
# in the pgdata volume, and the owner account already lives in that database.

# Read by postgres on its first boot to create the role, and used inside
# DATABASE_URL below to connect as it.
POSTGRES_PASSWORD=${PG_PASSWORD}

# Ryot's one required datastore setting. postgres:// with the role above.
DATABASE_URL=postgres://ryot:${PG_PASSWORD}@db:5432/ryot

# Gates the registerUser GraphQL mutation while USERS_ALLOW_REGISTRATION=false,
# so only a caller holding this token (the init one-shot) can create a user.
SERVER_ADMIN_ACCESS_TOKEN=${ADMIN_ACCESS_TOKEN}

# The workspace owner, created by panelalpha/ryot/init.sh over Ryot's GraphQL
# API before anything is reachable. The first user Ryot registers is made an
# admin automatically.
RYOT_OWNER_USERNAME=${OWNER_USER}
RYOT_OWNER_PASSWORD=${OWNER_PASSWORD}
EOF
    )
    (
        umask 077
        cat > "${NOTE}" <<EOF
Ryot owner for this account
===========================

  username: ${OWNER_USER}
  password: ${OWNER_PASSWORD}

Created on the first deploy and never changed by PanelAlpha afterwards. The
first user Ryot registers becomes an admin, and this recipe registers it over
the GraphQL API (with the admin access token) before the site is reachable, so
the usual "first visitor becomes the admin" window is never open.

SELF-SERVICE REGISTRATION IS CLOSED. USERS_ALLOW_REGISTRATION is set to false,
so the public sign-up page is disabled and strangers cannot create accounts. To
invite more users, register them from the admin account (Settings > Users) or
temporarily flip USERS_ALLOW_REGISTRATION in the account env vars. Each Ryot
user's tracked data is isolated to that user regardless.

DATA
  Everything (the owner, every other user, and all tracked media / fitness /
  collection data) lives in the PostgreSQL database on a named Docker volume
  that survives redeploy and storage reclaim. Uploaded images are proxied to
  the metadata providers or an optional S3 bucket, so there is no local upload
  store to persist for a default deploy. The secrets above are kept in
  ${STORE_DIR} (0600). Do not delete this directory.
EOF
    )
    chmod 600 "${ENV_FILE}" "${NOTE}"
    say "generated secrets -> ${ENV_FILE}; notes in ${NOTE}"
else
    say "reusing the secrets in ${ENV_FILE}"
fi

# Pre-pull the pinned images so `compose up` starts fast and an image NotFound
# surfaces here (best effort).
docker pull ignisda/ryot:v10.5.0 >/dev/null 2>&1 || true
docker pull postgres:18-alpine >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
say "prepare complete"
