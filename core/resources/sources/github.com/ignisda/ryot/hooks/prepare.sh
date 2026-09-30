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
# (engine#173). The DB password and the admin access token must live here and
# stay stable: on a redeploy the role already exists in the pgdata volume with
# the old password. The owner login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha/ryot"
ENV_FILE="${STORE_DIR}/ryot.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_FILE}" ]; then
    # No '/', '+', '=' or ':' -- these values are read back by a POSIX shell,
    # embedded in a JSON GraphQL body without escaping, and one of them goes
    # inside a postgres:// URL where ':' and '@' are delimiters.
    PG_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
    # Admin access token: gates registerUser when registration is disabled, so
    # only the init one-shot can create users. hex so it embeds cleanly in JSON.
    ADMIN_ACCESS_TOKEN="$(openssl rand -hex 24)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated. Deleting
# this file does not reset the application: POSTGRES_PASSWORD is already stored
# in the pgdata volume.

# Read by postgres on its first boot to create the role, and used inside
# DATABASE_URL below to connect as it.
POSTGRES_PASSWORD=${PG_PASSWORD}

# Ryot's one required datastore setting. postgres:// with the role above.
DATABASE_URL=postgres://ryot:${PG_PASSWORD}@db:5432/ryot

# Gates the registerUser GraphQL mutation while USERS_ALLOW_REGISTRATION=false,
# so only a caller holding this token (the init one-shot) can create a user.
SERVER_ADMIN_ACCESS_TOKEN=${ADMIN_ACCESS_TOKEN}
EOF
    )
    chmod 600 "${ENV_FILE}"
    say "generated secrets -> ${ENV_FILE}"
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
