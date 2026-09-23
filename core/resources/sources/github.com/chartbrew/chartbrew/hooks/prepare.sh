#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Persists this account's secrets where a
# redeploy will not delete them, then writes the env_files the compose reads.
# Rotating any of these breaks the running instance: a new DB password locks the
# app out of the mysql volume, a new CB_ENCRYPTION_KEY makes every stored
# datasource credential undecryptable, a new owner password silently changes the
# login.
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
BULLMQ_PASSWORD=$(gen_or_read "${STORE}/bullmq_password" openssl rand -hex 16)
OWNER_PASSWORD=$(gen_or_read "${STORE}/owner_password" openssl rand -hex 16)
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
CB_BULLMQ_USERNAME=chartbrew
CB_BULLMQ_PASSWORD=${BULLMQ_PASSWORD}
CB_ADMIN_MAIL=${OWNER_EMAIL}
EOF

# --- owner seeding, read by the init one-shot (files/.../init.sh) ---
cat > "${STORE}/owner.env" <<EOF
OWNER_NAME=Owner
OWNER_EMAIL=${OWNER_EMAIL}
OWNER_PASSWORD=${OWNER_PASSWORD}
EOF

chmod 600 "${STORE}"/*.env

cat > "${STORE}/credentials.txt" <<EOF
Chartbrew on this account
=========================

OWNER LOGIN (seeded once, on the first deploy)
  URL:      this account's URL (log in from the front page)
  Email:    ${OWNER_EMAIL}
  Password: ${OWNER_PASSWORD}

  Change it from Settings > Profile after first login; a redeploy will not
  reset it (the owner is seeded only when no user exists yet).

REGISTRATION
  Public signup is disabled (CB_RESTRICT_SIGNUP=1): once the owner exists,
  POST /user is refused. Invite teammates from Settings > Members (needs SMTP).

BULLMQ QUEUE DASHBOARD (/api/apps/queues, HTTP basic auth)
  Username: chartbrew
  Password: ${BULLMQ_PASSWORD}

SECRETS
  The DB password, Redis password, CB_ENCRYPTION_KEY (encrypts stored
  datasource credentials) and CB_SECRET live in ${STORE} (0600), generated
  once and reused on every redeploy. Do NOT delete this directory: a new
  encryption key makes every saved connection unreadable.

EMAIL (optional)
  Team invites and password-reset emails need SMTP. Set CB_MAIL_* in
  ${STORE}/app.env and redeploy.
EOF
chmod 600 "${STORE}/credentials.txt"

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

say "prepare complete; secrets in ${STORE}"
