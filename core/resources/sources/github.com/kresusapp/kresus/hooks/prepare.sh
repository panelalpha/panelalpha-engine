#!/bin/bash
# Generate Kresus's secrets once and materialise ~/project/.env for compose.
#
# ~/project is re-cloned and wiped on every redeploy (engine#173), so the
# postgres password and the export salt live in the account's persistent
# ~/.panelalpha/kresus (the only writable, rebuild-surviving dir). They are
# generated once and reused on every later deploy, so the encrypted data keeps
# working across redeploys. The basic-auth login is the engine's (`credentials:`
# in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e

PA_DIR="${HOME}/.panelalpha/kresus"
STORE="${PA_DIR}/secrets.env"
AUTH_ENV="${PA_DIR}/auth.env"

mkdir -p "${PA_DIR}"
chmod 700 "${PA_DIR}"

if [ ! -f "${STORE}" ]; then
  umask 077
  DB_PASS="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 32)"
  SALT="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)"

  {
    printf 'KRESUS_DB_PASSWORD=%s\n' "${DB_PASS}"
    printf 'KRESUS_SALT=%s\n' "${SALT}"
  } > "${STORE}"
  chmod 600 "${STORE}"
  echo "kresus: generated db and salt secrets in ${PA_DIR}."
else
  echo "kresus: reusing existing secrets in ${PA_DIR}."
fi

# Kresus reads the login as one user:pass value (server/config.ts splits on the
# first ':'; the engine's password has no ':'). Rewritten every deploy into a
# 0600 env_file, never into ~/project/.env, which the engine republishes at 644.
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
( umask 077; printf 'KRESUS_AUTH=%s:%s\n' "${KRESUS_AUTH_USER}" "${KRESUS_AUTH_PASSWORD}" > "${AUTH_ENV}" )
chmod 600 "${AUTH_ENV}"

# Compose reads ~/project/.env for ${...} interpolation. The engine merges its
# own keys onto this file without dropping ours (EnvFile::merge); writing it
# also pre-empts any repo .env being copied over it (engine#218).
cd "${HOME}/project"
cp "${STORE}" .env
chmod 600 .env
echo "kresus: wrote ~/project/.env for compose interpolation."
