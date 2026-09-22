#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
set -e
cd ~/project

# The RocksDB is all of Conduit's state - accounts, rooms, messages, media - and
# ~/project is emptied on every deploy (engine#173), so it must live in
# ~/.panelalpha, the only writable directory the wipe never touches. The frozen
# server_name lives beside it.
STATE="${HOME}/.panelalpha/conduit"
DATA_DIR="${STATE}/data"
mkdir -p "${DATA_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STATE}"

# Token-gated registration. The token is generated once and kept out of
# ~/project so a redeploy cannot regenerate it and lock existing users out; 0600
# because anyone holding it can create an account. It is reused across redeploys
# and is what the owner hands to people they want to let register. Conduit
# refuses to boot on an empty token, so this is always a real 32-char secret.
TOKEN_FILE="${STATE}/registration_token"
if [ ! -f "${TOKEN_FILE}" ]; then
    umask 077
    head -c 400 /dev/urandom | tr -dc 'A-Za-z0-9' | cut -c1-32 > "${TOKEN_FILE}"
    echo "[panelalpha] conduit: generated ${TOKEN_FILE} (the registration token lives here)"
fi
chmod 600 "${TOKEN_FILE}"

# The published image runs as root; the override runs it as this account's uid
# so the bind mount it writes is owned by the user it runs as. Values are
# consumed by ${...} interpolation from ~/project/.env at `docker compose up`.
# The helper names deliberately avoid the CONDUIT_ prefix so Conduit's Figment
# env parser never picks them up. Writing .env here also makes the engine treat
# the env as already-prepared (ProjectEnvironment does not copy a repo
# .env.example over it, engine#218).
{
  echo "CONTAINER_UID=$(id -u)"
  echo "CDT_DATA_DIR=${DATA_DIR}"
  echo "CDT_REG_TOKEN=$(cat "${TOKEN_FILE}")"
} > .env
