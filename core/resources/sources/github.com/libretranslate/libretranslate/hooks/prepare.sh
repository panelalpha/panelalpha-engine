#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: generate the managed API key once into a place a redeploy will not
# wipe, and make sure the .env the compose references exists.
#
# LibreTranslate keeps NO user data (stateless translation), so there is no admin
# or user account to seed. The only credential this recipe owns is the managed
# API key used to call /translate programmatically once the compute endpoint is
# gated (LT_REQUIRE_API_KEY_SECRET). The app's own rotating request secret lives
# in Redis and is not our concern.
set -e
cd ~/project

say() { echo "[libretranslate] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The key written under ~/project would be regenerated on every
# rebuild and no longer match the one already in the SQLite key DB on the volume.
# So it lives here and stays stable; keyinit re-asserts it into the DB each deploy.
STORE_DIR="${HOME}/.panelalpha/libretranslate"
ENV_FILE="${STORE_DIR}/api-key.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
if [ ! -f "${ENV_FILE}" ]; then
    # A UUID-shaped key: LibreTranslate's own `ltmanage keys add` mints uuid4
    # values, and the key travels in JSON/query params, so keep it URL/JSON-safe.
    API_KEY="$(cat /proc/sys/kernel/random/uuid 2>/dev/null || openssl rand -hex 16)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. The keyinit one-shot in
# docker-compose.yml inserts this value into the API-key SQLite DB on every deploy
# (idempotent), so it stays valid across redeploys. Do not delete.
LT_MANAGED_API_KEY=${API_KEY}
EOF
        cat > "${NOTE}" <<EOF
LibreTranslate on this account
==============================

LibreTranslate is a self-hosted machine-translation API with a small built-in
web UI. It stores NO user data -- every request is stateless compute -- so there
is no login. The web UI at this account's URL works with no key.

The API compute endpoint (/translate, /detect) is GATED to stop the account
becoming an open translation relay. Scripted/programmatic callers must send this
managed API key; the web UI is exempt (it sends the server's rotating secret).

MANAGED API KEY (created once, on the first deploy)
  Key: ${API_KEY}

  Example:
    curl -s -X POST "<this account's URL>/translate" \\
      -H "Content-Type: application/json" \\
      -d '{"q":"hello","source":"en","target":"es","api_key":"${API_KEY}"}'

  Public (no key needed):
    GET  <this account's URL>/languages     list loaded languages
    GET  <this account's URL>/health        liveness

LANGUAGES
  Only en, es, fr are loaded by default (LT_LOAD_ONLY) to keep the model
  download small. To add languages, widen LT_LOAD_ONLY in the compose file and
  redeploy; new model packs download once and persist on the lt-models volume.

DATA
  Argos language models live under /home/libretranslate/.local and the API-key
  SQLite DB under /app/db, each on its own named Docker volume, so both survive
  a redeploy (models are not re-downloaded).

CREDENTIALS FILE
  This key lives in ${STORE_DIR} (0600). Do not delete this directory.
EOF
    )
    chmod 600 "${ENV_FILE}" "${NOTE}"
    say "managed API key written to ${ENV_FILE}; notes in ${NOTE}"
else
    say "reusing the managed API key in ${ENV_FILE}"
fi

# The compose libretranslate service lists ~/project/.env as an env_file; make
# sure it exists even when the platform has not written one yet, so `up` does not
# abort on a missing file. The account's env_vars are merged in.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull libretranslate/libretranslate:v1.9.6 >/dev/null 2>&1 || true
say "prepare complete"
