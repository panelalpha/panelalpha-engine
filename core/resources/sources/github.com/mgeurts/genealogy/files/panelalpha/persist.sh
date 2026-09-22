#!/bin/bash
# Runs inside the app container (as the account uid) before migrate/optimize on
# every boot. /app is the mounted checkout (wiped and recloned each deploy),
# /app/.pa-data is the ~/.panelalpha/genealogy bind mount (survives). Makes the
# perishable checkout point at the durable mount, idempotently.
set -e

PA=/app/.pa-data

# Self-heal the upload tree on the mount (prepare.sh made it; a hand-cleaned
# mount or a new disk layout still gets what the app writes into).
mkdir -p \
  "$PA/storage-app/public/photos" \
  "$PA/storage-app/public/photos-096" \
  "$PA/storage-app/public/photos-384" \
  "$PA/storage-app/public/profile-photos" \
  "$PA/storage-app/public/profiles" \
  "$PA/storage-app/public/files" \
  "$PA/storage-app/public/gedcom" \
  "$PA/storage-app/backups"

# storage/app (the only user files not in MySQL) -> the durable mount.
# storage/framework and storage/logs stay in the checkout; they are rebuilt
# each deploy and hold nothing worth keeping.
if [ ! -L /app/storage/app ]; then
  rm -rf /app/storage/app
  ln -s "$PA/storage-app" /app/storage/app
fi

# public/storage -> storage/app/public. This is what `artisan storage:link`
# does, redone every boot because the laravel recipe runs it install-only and
# public/ is wiped on every redeploy.
rm -rf /app/public/storage
ln -s /app/storage/app/public /app/public/storage

# A stable APP_KEY. Generated once onto the mount; without this the laravel
# recipe's install-only key:generate is lost on the next redeploy and every
# encrypted value (2FA secrets) and session breaks.
KEYFILE="$PA/app-key"
if [ ! -s "$KEYFILE" ]; then
  ( umask 077; printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > "$KEYFILE" )
  chmod 600 "$KEYFILE"
fi
KEY="$(cat "$KEYFILE")"

# Write it into .env without sed (the base64 value contains / and +).
if [ -f /app/.env ]; then
  tmp="$(mktemp)"
  grep -v '^APP_KEY=' /app/.env > "$tmp" || true
  printf 'APP_KEY=%s\n' "$KEY" >> "$tmp"
  mv "$tmp" /app/.env
fi

echo "genealogy persist: storage/app + public/storage linked, APP_KEY restored"
