#!/bin/bash
set -e
cd ~/project

# Gancio keeps everything mutable under GANCIO_DATA=/app/data: the SQLite DB,
# config.json (which holds the session `secret`), uploaded event images, logs.
# ~/project is wiped every deploy (engine#173), so the data must live in
# ~/.panelalpha/gancio/data, which the wipe never touches. ~/.panelalpha is
# owned by this account, so the hook can create the tree here.
DATA_DIR="${HOME}/.panelalpha/gancio/data"
mkdir -p "${DATA_DIR}"

# The published image runs as its baked-in `node` (uid 1000) and ships
# /app/data owned by root, so the app cannot write a bind mount owned by this
# account. Record the account uid and the absolute data path; the compose runs
# the container as that uid and bind-mounts DATA_DIR, so the mount it writes is
# owned by the user it runs as. Both are consumed by ${...} interpolation from
# ~/project/.env at `docker compose up`. Writing .env here also makes the
# engine treat the env as already-prepared (ProjectEnvironment sees .env and
# does not copy the repo's .env.example over it, engine#218).
{
  echo "CONTAINER_UID=$(id -u)"
  echo "GANCIO_DATA_DIR=${DATA_DIR}"
} > .env
