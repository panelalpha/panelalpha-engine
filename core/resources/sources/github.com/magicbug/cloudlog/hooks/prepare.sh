#!/bin/bash
# Keep the installer's config files and the uploads in ~/.panelalpha: ~/project
# is re-cloned on every deploy. The override mounts $DATA at /pa-data.
set -e
cd ~/project
DATA="${HOME}/.panelalpha/cloudlog"
mkdir -p "$DATA/config"
chmod 700 "$DATA"
for d in uploads backup assets/qslcard images/eqsl_card_images; do
    [ -d "$DATA/$d" ] || { mkdir -p "$(dirname "$DATA/$d")"; cp -a "$d" "$DATA/$d"; }
done
# Dangling until the installer writes through them; then the app is configured.
ln -sfn /pa-data/config/config.php application/config/config.php
ln -sfn /pa-data/config/database.php application/config/database.php
