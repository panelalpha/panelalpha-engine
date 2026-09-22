#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put the JWT secret somewhere the next deploy will not delete, and make
# sure the .env the compose references exists.
set -e
cd ~/project

# ~/.panelalpha/donetick survives a redeploy; ~/project is emptied every deploy
# (engine#173). The JWT secret written under ~/project would be regenerated on
# every rebuild, and a new secret invalidates every session token.
STORE_DIR="${HOME}/.panelalpha/donetick"
ENV_FILE="${STORE_DIR}/donetick.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# jwt.secret has no usable default (the shipped config carries a placeholder and
# the app must be given a real one); it must be >=32 chars and stable across
# redeploys. hex(32) is 64 clean chars that read back cleanly from an env file.
if [ ! -f "${ENV_FILE}" ]; then
    JWT_SECRET="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. Rotating this logs
# every user out (it signs the session/refresh JWTs). Do not delete it.
DT_JWT_SECRET=${JWT_SECRET}
EOF
    )
    chmod 600 "${ENV_FILE}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull donetick/donetick:v0.1.79 >/dev/null 2>&1 || true
