#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
#
# Crafty keeps ALL state (the SQLite db, config.json, keys, the generated admin
# creds, each Minecraft world, backups, logs, imports) under its data dirs, and
# ~/project is emptied on every deploy (engine#173). So the state lives in
# ~/.panelalpha/crafty, the only writable directory the wipe never touches, and
# the compose override bind-mounts each dir from there.
set -e
cd ~/project

CRAFTY_DATA="${HOME}/.panelalpha/crafty"
mkdir -p "${CRAFTY_DATA}/config" \
         "${CRAFTY_DATA}/servers" \
         "${CRAFTY_DATA}/backups" \
         "${CRAFTY_DATA}/logs" \
         "${CRAFTY_DATA}/import"
chmod 700 "${HOME}/.panelalpha" "${CRAFTY_DATA}"

# The dockerfile strategy declares env_file: .env, and compose interpolates
# ${CRAFTY_DATA} in the override from this same file. Writing .env here also
# stops ProjectEnvironment from copying a repo .env.example over it (engine#218).
{
  echo "CRAFTY_DATA=${CRAFTY_DATA}"
  echo "TZ=Etc/UTC"
} > .env

echo "[panelalpha] crafty: state dir ${CRAFTY_DATA} prepared; ~/project may be wiped freely."
