#!/bin/bash
# Runs after clone, before compose up, as the account user with its HOME set.
# Pre-creates the persistent bind-mount source so Docker does not create it as
# root (which would leave the container, running as the account uid, unable to
# write its uploads or APP_KEY). Only creates what is missing; never touches
# existing family data on a redeploy.
set -e

PERSIST="$HOME/.panelalpha/genealogy"
mkdir -p \
  "$PERSIST/storage-app/public/photos" \
  "$PERSIST/storage-app/public/photos-096" \
  "$PERSIST/storage-app/public/photos-384" \
  "$PERSIST/storage-app/public/profile-photos" \
  "$PERSIST/storage-app/public/profiles" \
  "$PERSIST/storage-app/public/files" \
  "$PERSIST/storage-app/public/gedcom" \
  "$PERSIST/storage-app/backups"
chmod 700 "$PERSIST"
