#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
#
# SCM-Manager keeps ALL state (the H2 db, every hosted repo, config, plugins)
# under /var/lib/scm, and ~/project is emptied on every deploy (engine#173). So
# the data lives in ~/.panelalpha/scm-manager, the only writable dir the wipe
# never touches, and the compose override bind-mounts it. The admin login is the
# engine's (`credentials:` in panelalpha.yaml): SCM_ADMIN_USER /
# SCM_ADMIN_PASSWORD in ~/.panelalpha/app-credentials.env, the override's env_file.
set -e
cd ~/project

STATE="${HOME}/.panelalpha/scm-manager"
DATA="${STATE}/data"
mkdir -p "$DATA"
chmod 700 "${HOME}/.panelalpha" "$STATE"

# The dockerfile strategy declares env_file: .env and interpolates ${...} in the
# override from this same file. Writing .env here also stops ProjectEnvironment
# from copying a repo .env.example over it (engine#218).
{
  echo "CONTAINER_UID=$(id -u)"
  echo "SCM_DATA=${DATA}"
  # The admin contact email is derived by the entrypoint from the account's
  # injected public FQDN (SERVER_NAME), which SCM-Manager accepts as a valid
  # address; a bare hostname would be rejected, so none is set here.
} > .env

echo "[panelalpha] scm-manager: state dir ${DATA} prepared; ~/project may be wiped freely."
