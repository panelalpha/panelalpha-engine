#!/bin/bash
# Prepare a persistent, secured data dir for tube before first boot.
#
# tube keeps ALL state under /data and ~/project is wiped every redeploy
# (engine#173), so the data lives in the account's persistent ~/.panelalpha/tube
# (bind-mounted to /data by the override). This hook generates the upload
# password once, records a recoverable credential file, and writes the account
# uid/gid for the compose ${...} interpolation.
set -e

DATA_DIR="${HOME}/.panelalpha/tube"
mkdir -p "${DATA_DIR}"

# Upload-auth password. tube gates POST /upload behind HTTP basic auth (user
# "uploader") only when auth_password is set; unset means anyone may upload.
# Generated once (0600, never in ~/project) and reused on every redeploy.
SECRETS="${DATA_DIR}/secrets.env"
if [ ! -f "${SECRETS}" ]; then
  umask 077
  echo "auth_password=$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 32)" > "${SECRETS}"
  chmod 600 "${SECRETS}"
  echo "tube: generated upload password."
else
  echo "tube: reusing existing upload password."
fi

# Owner-recoverable credential record — 0600, in the persistent dir.
CRED="${DATA_DIR}/panelalpha-credentials.txt"
if [ ! -f "${CRED}" ]; then
  # shellcheck disable=SC1090
  . "${SECRETS}"
  umask 077
  cat > "${CRED}" <<EOF
tube upload login (HTTP basic auth on /upload)
  username: uploader
  password: ${auth_password}

NOTE: POST /import (import a YouTube/Vimeo video by URL) is NOT gated by upstream
and stays open to anonymous requests. It is bounded to youtube.com/vimeo.com, so
it is a disk-fill vector, not an arbitrary SSRF. If anonymous writes matter to
you, keep this site behind a front auth layer or a trusted network.
EOF
  chmod 600 "${CRED}"
fi

# uid/gid for the override's PUID/PGID interpolation. Writing .env here also
# stops the engine copying a repo .env.example over it (engine#218). Only the
# account uid/gid go here — never the upload password.
cd "${HOME}/project"
{
  echo "CONTAINER_UID=$(id -u)"
  echo "CONTAINER_GID=$(id -g)"
} > .env

echo "tube: prepared ${DATA_DIR} (uid=$(id -u) gid=$(id -g))."
