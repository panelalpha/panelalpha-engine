#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Persists this account's secrets where a
# redeploy will not delete them, then writes the env_files the compose reads.
# Rotating any of these breaks the running instance: a new DB password locks the
# app out of the mysql volume, a new CB_ENCRYPTION_KEY makes every stored
# datasource credential undecryptable. The owner login and the BullMQ dashboard
# login are the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
set -e

say() { echo "[chartbrew] $*" >&2; }

# ~/project is wiped every deploy; ~/.panelalpha survives, so secrets live there
# and are generated only on the first deploy.
STORE="${HOME}/.panelalpha/chartbrew"
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

# hex only: read back cleanly from env files, JSON and a MySQL DSN (no /,+,=,@,").
DB_PASSWORD=$(gen_or_read "${STORE}/db_password" openssl rand -hex 16)
REDIS_PASSWORD=$(gen_or_read "${STORE}/redis_password" openssl rand -hex 16)
# CB_ENCRYPTION_KEY must be exactly 64 hex chars (AES-256); see cbCrypto.js.
ENCRYPTION_KEY=$(gen_or_read "${STORE}/encryption_key" openssl rand -hex 32)
# CB_SECRET signs legacy share tokens; kept stable so shares survive a redeploy.
CB_SECRET=$(gen_or_read "${STORE}/cb_secret" openssl rand -hex 32)
OWNER_EMAIL="owner@chartbrew.local"

umask 077

# --- db container (mysql:8.4) reads this on first boot; password is baked into
# the named volume and must never change afterwards. ---
cat > "${STORE}/db.env" <<EOF
MYSQL_DATABASE=chartbrew
MYSQL_USER=chartbrew
MYSQL_PASSWORD=${DB_PASSWORD}
MYSQL_RANDOM_ROOT_PASSWORD=yes
MYSQL_DEFAULT_AUTH=caching_sha2_password
EOF

# --- redis container reads this for --requirepass ---
cat > "${STORE}/redis.env" <<EOF
REDIS_PASSWORD=${REDIS_PASSWORD}
EOF

# --- chartbrew app secrets. Kept out of the compose YAML so the placeholder
# filler never sees them; only non-secret config lives in the compose. ---
cat > "${STORE}/app.env" <<EOF
CB_DB_DIALECT=mysql
CB_DB_HOST=db
CB_DB_PORT=3306
CB_DB_NAME=chartbrew
CB_DB_USERNAME=chartbrew
CB_DB_PASSWORD=${DB_PASSWORD}
CB_REDIS_HOST=redis
CB_REDIS_PORT=6379
CB_REDIS_PASSWORD=${REDIS_PASSWORD}
CB_ENCRYPTION_KEY=${ENCRYPTION_KEY}
CB_SECRET=${CB_SECRET}
CB_ADMIN_MAIL=${OWNER_EMAIL}
EOF

chmod 600 "${STORE}"/*.env

# docker compose reads ./.env in the project dir for interpolation; keep it
# present (empty) so `docker compose up` never warns/aborts on a missing file.
cd "${HOME}/project"
touch .env

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull razvanilin/chartbrew:5.3.2 || true
docker pull mysql:8.4 || true
docker pull redis:7-alpine || true
docker pull nginx:1.27-alpine || true
docker pull curlimages/curl:8.11.1 || true

say "prepare complete"
