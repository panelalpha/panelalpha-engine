#!/bin/bash
# Prepare a persistent, secured data dir for yarnd before first boot.
#
# yarnd keeps ALL state under -d /data and ~/project is wiped every redeploy,
# so the data lives in the account's persistent ~/.panelalpha/yarn
# (bind-mounted to /data by the override). This hook:
#   - creates that dir,
#   - generates the three production pod secrets ONCE (reused forever) so
#     sessions/tokens survive a restart, 0600, never in ~/project,
#   - (the admin login for the /setup seed is the engine's, `credentials:` in
#     panelalpha.yaml, mounted read-only for pa-run.sh),
#   - records the account uid/gid and the container secrets path into
#     ~/project/.env for the compose ${...} interpolation.
set -e

DATA_DIR="${HOME}/.panelalpha/yarn"
mkdir -p "${DATA_DIR}"

# 64-char secrets from /dev/urandom (matches upstream tools/gen-secrets.sh; no
# openssl dependency). Generated once and reused on every redeploy.
gen_secret() {
  tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 64
}

SECRETS="${DATA_DIR}/secrets.env"
if [ ! -f "${SECRETS}" ]; then
  umask 077
  {
    echo "COOKIE_SECRET=$(gen_secret)"
    echo "API_SIGNING_KEY=$(gen_secret)"
    echo "MAGICLINK_SECRET=$(gen_secret)"
  } > "${SECRETS}"
  chmod 600 "${SECRETS}"
  echo "yarnd: generated pod secrets."
else
  echo "yarnd: reusing existing pod secrets."
fi

# The dockerfile strategy declares env_file: .env and the override interpolates
# PUID/PGID from it. Writing .env here also stops the engine copying a repo
# .env.example over it. Only the account uid/gid go here — never
# the secrets.
cd "${HOME}/project"
{
  echo "CONTAINER_UID=$(id -u)"
  echo "CONTAINER_GID=$(id -g)"
} > .env

echo "yarnd: prepared ${DATA_DIR} (uid=$(id -u) gid=$(id -g))."
