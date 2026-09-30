#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Jobs the compose file cannot do itself:
# put the JWT secret somewhere the next deploy will not delete, and make sure the
# .env the compose references exists. The Owner login is the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

# ~/.panelalpha/ech0 survives a redeploy; ~/project is emptied every deploy
# (engine#173). Secrets written under ~/project would be regenerated on every
# rebuild -- a new JWT secret logs everyone out.
STORE_DIR="${HOME}/.panelalpha/ech0"
ENV_FILE="${STORE_DIR}/ech0.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# JWT_SECRET: getJWTSecret() falls back to a per-boot random secret when unset,
# which invalidates every session on restart; pin a real stable one.
if [ ! -f "${ENV_FILE}" ]; then
    JWT_SECRET="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. Rotating JWT_SECRET
# logs every user out. Do not delete this file.
JWT_SECRET=${JWT_SECRET}
EOF
    )
    chmod 600 "${ENV_FILE}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull sn0wl1n/ech0:v5.7.0 >/dev/null 2>&1 || true
