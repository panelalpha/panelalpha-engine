#!/bin/bash
# Generate Kresus's secrets once and materialise ~/project/.env for compose.
#
# ~/project is re-cloned and wiped on every redeploy (engine#173), so the
# basic-auth password, the postgres password and the export salt live in the
# account's persistent ~/.panelalpha/kresus (the only writable, rebuild-
# surviving dir). They are generated once and reused on every later deploy, so
# the owner's login and the encrypted data keep working across redeploys.
set -e

PA_DIR="${HOME}/.panelalpha/kresus"
STORE="${PA_DIR}/secrets.env"
CRED="${PA_DIR}/panelalpha-credentials.txt"

mkdir -p "${PA_DIR}"
chmod 700 "${PA_DIR}"

if [ ! -f "${STORE}" ]; then
  umask 077
  AUTH_USER="admin"
  # Colon-free: Kresus (server/config.ts) splits KRESUS_AUTH on the first ':'
  # into user:pass, and a colon in the password would truncate/void it.
  AUTH_PASS="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 32)"
  DB_PASS="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 32)"
  SALT="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)"

  {
    printf 'KRESUS_AUTH=%s:%s\n' "${AUTH_USER}" "${AUTH_PASS}"
    printf 'KRESUS_DB_PASSWORD=%s\n' "${DB_PASS}"
    printf 'KRESUS_SALT=%s\n' "${SALT}"
  } > "${STORE}"
  chmod 600 "${STORE}"

  cat > "${CRED}" <<EOF
Kresus HTTP Basic Auth (generated $(date -u +%FT%TZ))
url:      set by PanelAlpha (your project's public domain)
username: ${AUTH_USER}
password: ${AUTH_PASS}

Change or disable this from the Kresus reverse-proxy / KRESUS_AUTH if you put
another auth layer in front. This password gates the entire instance.
EOF
  chmod 600 "${CRED}"
  echo "kresus: generated basic-auth, db and salt secrets in ${PA_DIR}."
else
  echo "kresus: reusing existing secrets in ${PA_DIR}."
fi

# Compose reads ~/project/.env for ${...} interpolation. The engine merges its
# own keys onto this file without dropping ours (EnvFile::merge); writing it
# also pre-empts any repo .env being copied over it (engine#218).
cd "${HOME}/project"
cp "${STORE}" .env
chmod 600 .env
echo "kresus: wrote ~/project/.env for compose interpolation."
