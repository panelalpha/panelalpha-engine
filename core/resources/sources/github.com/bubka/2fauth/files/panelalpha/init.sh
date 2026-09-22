#!/bin/sh
# One-shot installer that prepares the /2fauth volume and seeds the owner admin
# BEFORE the app container serves (the app depends_on this completing). It wires
# the DB and storage onto the volume exactly the way the image entrypoint does,
# installs the schema + Passport keys, seeds the admin, closes registration, and
# writes the image's install marker so the app entrypoint skips its own
# migrate:fresh and just serves. Must exit 0, including on a redeploy.
set -e

cd /srv

# --- storage on the volume (mirrors the image entrypoint) ------------------
# Passport OAuth keys and the file cache live under storage/, so it must be the
# same directory the app will use.
if [ ! -d /2fauth/storage ]; then
  mv /srv/storage /2fauth/storage
else
  rm -rf /srv/storage
fi
ln -sfn /2fauth/storage /srv/storage

# --- SQLite database on the volume (mirrors the image entrypoint) -----------
# DB_CONNECTION=sqlite and DB_DATABASE=/srv/database/database.sqlite are baked
# into the image; symlink that path onto the volume so artisan writes there.
[ -f /2fauth/database.sqlite ] || touch /2fauth/database.sqlite
rm -f /srv/database/database.sqlite
ln -sfn /2fauth/database.sqlite /srv/database/database.sqlite

# --- install / migrate ------------------------------------------------------
if [ ! -f /2fauth/installed ]; then
  echo "init: fresh volume, installing schema + Passport keys"
  php artisan migrate:fresh --force
  php artisan passport:install --no-interaction
else
  echo "init: existing volume, applying pending migrations only"
  php artisan migrate --force
fi

# --- seed admin + disable registration --------------------------------------
php /panelalpha/seed-admin.php

# --- public storage symlink + install marker --------------------------------
php artisan storage:link --quiet 2>/dev/null || true

# The app entrypoint runs migrate:fresh (which DROPS every table) only when this
# marker is absent, so writing it is what protects the seeded data. Same image
# as the app, so ${COMMIT} matches and the app skips re-setup entirely.
echo "${COMMIT}" > /2fauth/installed

# Drop any cached config/options so the app rebuilds them (the disableRegistration
# option in particular) from the DB on first request.
php artisan cache:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true

echo "init: complete"
