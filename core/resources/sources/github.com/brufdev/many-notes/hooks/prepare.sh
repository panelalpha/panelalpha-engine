#!/bin/bash
# Runs in the account shell after the clone and after overrides/ is in place,
# before `docker compose up`. Two jobs the compose file cannot do for itself:
# keep the APP_KEY somewhere the next deploy will not delete, and make sure the
# .env the compose references exists.
set -e
cd ~/project

# ~/.panelalpha/many-notes survives a redeploy; ~/project is emptied every
# deploy (engine#173). An APP_KEY written under ~/project would be regenerated
# on every rebuild -- a new key logs everyone out and makes every encrypted
# column and session unreadable.
STORE_DIR="${HOME}/.panelalpha/many-notes"
APP_ENV_FILE="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. base64:<32 raw bytes> is the only shape Laravel's Encrypter
# accepts for AES-256; config/app.php reads APP_KEY verbatim and the cipher check
# runs at boot, so a wrong-length key is a 500 on every page that touches a
# session. A real process env var wins over the .env the image bakes in
# (phpdotenv does not overwrite existing environment variables).
if [ ! -f "${APP_ENV_FILE}" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    (
        umask 077
        cat > "${APP_ENV_FILE}" <<EOF
# Written once on the first deploy, reused on every redeploy. Rotating APP_KEY
# logs everyone out and makes encrypted values unreadable. Do not delete it;
# the notes themselves live in the SQLite named volume and are unaffected.
APP_KEY=${APP_KEY}
EOF
    )
    chmod 600 "${APP_ENV_FILE}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. Account env_vars, if any, are merged into it by the platform.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull brufdev/many-notes:0.18 >/dev/null 2>&1 || true
