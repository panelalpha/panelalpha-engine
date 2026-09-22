#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Persists this account's secrets where a
# redeploy will not delete them, then writes the env_files the compose reads.
# Rotating any of these breaks the running instance: a new APP_KEY invalidates
# every encrypted cookie/session, a new Meilisearch master key orphans the
# search index and every browser search token, a new owner password silently
# changes the login. So they are generated only on the first deploy and reused.
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
OWNER_PASSWORD=$(gen_or_read "${STORE}/owner_password" openssl rand -hex 16)
OWNER_EMAIL="owner@bar-assistant.local"

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

# --- owner seeding, read by the init one-shot (seed-owner.php). ---
cat > "${STORE}/owner.env" <<EOF
OWNER_NAME=Owner
OWNER_EMAIL=${OWNER_EMAIL}
OWNER_PASSWORD=${OWNER_PASSWORD}
EOF

chmod 600 "${STORE}"/*.env

cat > "${STORE}/credentials.txt" <<EOF
Bar Assistant on this account
=============================

OWNER LOGIN (seeded once, on the first deploy)
  URL:      this account's URL (log in from the Salt Rim front page)
  Email:    ${OWNER_EMAIL}
  Password: ${OWNER_PASSWORD}

  Change it from your profile after first login; a redeploy will not reset it
  (the owner is seeded only when no user with this email exists yet).

REGISTRATION
  Public signup is disabled (ALLOW_REGISTRATION=false): the /api/auth/register
  endpoint returns 404 and the Salt Rim UI hides the form. To open signup,
  set ALLOW_REGISTRATION=true on the bar-assistant and salt-rim services and
  redeploy.

SECRETS (generated once, reused on every redeploy -- do NOT delete)
  APP_KEY               ${STORE}/app_key
  Meilisearch key       ${STORE}/meili_master_key
  A new APP_KEY invalidates every session; a new Meilisearch key orphans the
  search index.

EMAIL (optional)
  Password-reset and confirmation emails need SMTP. Set MAIL_* on the
  bar-assistant service and redeploy.
EOF
chmod 600 "${STORE}/credentials.txt"

# docker compose reads ./.env in the project dir for interpolation; keep it
# present (empty) so `docker compose up` never warns/aborts on a missing file.
cd "${HOME}/project"
touch .env

# Pre-pull so compose up starts fast and an image NotFound surfaces here.
docker pull barassistant/server:6.8.0 || true
docker pull barassistant/salt-rim:5.7.0 || true
docker pull getmeili/meilisearch:v1.50 || true
docker pull alpine:3 || true

say "prepare complete; secrets in ${STORE}"
