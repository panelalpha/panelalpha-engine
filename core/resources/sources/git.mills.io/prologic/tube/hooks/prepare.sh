#!/bin/bash
# Prepare a persistent, secured data dir for tube before first boot.
#
# tube keeps ALL state under /data and ~/project is wiped every redeploy,
# so the data lives in the account's persistent ~/.panelalpha/tube
# (bind-mounted to /data by the override). The upload password is the engine's
# (`credentials:` in panelalpha.yaml; `auth_password`, the name the old
# secrets.env used), mounted read-only for pa-run.sh. This hook writes the
# account uid/gid for the compose ${...} interpolation.
set -e

DATA_DIR="${HOME}/.panelalpha/tube"
mkdir -p "${DATA_DIR}"

# uid/gid for the override's PUID/PGID interpolation. Writing .env here also
# stops the engine copying a repo .env.example over it. Only the
# account uid/gid go here — never the upload password.
cd "${HOME}/project"
{
  echo "CONTAINER_UID=$(id -u)"
  echo "CONTAINER_GID=$(id -g)"
} > .env

echo "tube: prepared ${DATA_DIR} (uid=$(id -u) gid=$(id -g))."
