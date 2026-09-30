#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Persists this account's secrets where a
# redeploy will not delete them, then writes the env_files the compose reads.
# Rotating any of these breaks the running instance: a new APP_KEY invalidates
# every encrypted cookie/session, a new Meilisearch master key orphans the
# search index and every browser search token. So they are generated only on
# the first deploy and reused. The owner login is the engine's (`credentials:`
# in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e

say() { echo "[bar-assistant] $*" >&2; }

# ~/project is wiped every deploy; ~/.panelalpha survives.
STORE="${HOME}/.panelalpha/bar-assistant"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# gen_or_read <file> <generator...>: create with the generator if missing, print.
gen_or_read() {
    f="$1"; shift
    if [ ! -s "${f}" ]; then
        ( umask 077; "$@" > "${f}" )
    fi
    cat "${f}"
}

# base64:<32 raw bytes> -- the only shape Laravel's AES-256 encrypter accepts.
APP_KEY=$(gen_or_read "${STORE}/app_key" sh -c 'echo "base64:$(openssl rand -base64 32)"')
# Meilisearch master key: hex, reads back cleanly from env files and headers.
MEILI_KEY=$(gen_or_read "${STORE}/meili_master_key" openssl rand -hex 32)

umask 077

# --- Bar Assistant server secrets (APP_KEY + the Meilisearch key it uses). ---
cat > "${STORE}/app.env" <<EOF
APP_KEY=${APP_KEY}
MEILISEARCH_KEY=${MEILI_KEY}
EOF

# --- Meilisearch reads its master key here on first boot. ---
cat > "${STORE}/meili.env" <<EOF
MEILI_MASTER_KEY=${MEILI_KEY}
EOF

chmod 600 "${STORE}"/*.env

# docker compose reads ./.env in the project dir for interpolation; keep it
# present (empty) so `docker compose up` never warns/aborts on a missing file.
cd "${HOME}/project"
touch .env

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull barassistant/server:6.8.0 || true
docker pull barassistant/salt-rim:5.7.0 || true
docker pull getmeili/meilisearch:v1.50 || true
docker pull alpine:3 || true

say "prepare complete"
