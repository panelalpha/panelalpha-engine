#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
#
# SCM-Manager keeps ALL state (the H2 db, every hosted repo, config, plugins)
# under /var/lib/scm, and ~/project is emptied on every deploy (engine#173). So
# the data lives in ~/.panelalpha/scm-manager, the only writable dir the wipe
# never touches, and the compose override bind-mounts it. The admin password is
# generated here once and reused across redeploys.
set -e
cd ~/project

STATE="${HOME}/.panelalpha/scm-manager"
DATA="${STATE}/data"
mkdir -p "$DATA"
chmod 700 "${HOME}/.panelalpha" "$STATE"

# Admin password, generated once. 0600, owner-only, never echoed to the deploy
# log. The entrypoint reads admin_password to seed the first admin; the
# human-readable credentials file is for the owner.
PW_FILE="${STATE}/admin_password"
if [ ! -s "$PW_FILE" ]; then
  umask 077
  head -c 400 /dev/urandom | tr -dc 'A-Za-z0-9' | cut -c1-28 > "$PW_FILE"
  {
    echo "SCM-Manager auto-provisioned administrator"
    echo "user:     pa-admin"
    echo "password: $(cat "$PW_FILE")"
  } > "${STATE}/admin_credentials.txt"
  chmod 600 "${STATE}/admin_credentials.txt"
  echo "[panelalpha] scm-manager: generated admin password -> ${STATE}/admin_credentials.txt"
fi
chmod 600 "$PW_FILE"

# The dockerfile strategy declares env_file: .env and interpolates ${...} in the
# override from this same file. Writing .env here also stops ProjectEnvironment
# from copying a repo .env.example over it (engine#218).
{
  echo "CONTAINER_UID=$(id -u)"
  echo "SCM_DATA=${DATA}"
  echo "SCM_ADMIN_PW_FILE=${PW_FILE}"
  echo "SCM_ADMIN_USER=pa-admin"
  # The admin contact email is derived by the entrypoint from the account's
  # injected public FQDN (SERVER_NAME), which SCM-Manager accepts as a valid
  # address; a bare hostname would be rejected, so none is set here.
} > .env

echo "[panelalpha] scm-manager: state dir ${DATA} prepared; ~/project may be wiped freely."
