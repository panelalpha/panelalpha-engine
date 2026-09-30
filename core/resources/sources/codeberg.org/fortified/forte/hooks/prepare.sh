#!/bin/bash
# Keep Forte's config file and store/ in ~/.panelalpha across redeploys, and
# enable upstream's own rewrite rules (index.php?req=...).
set -e
DATA="${HOME}/.panelalpha/forte"
mkdir -p "$DATA/store"
chmod 700 "$DATA"
# Empty = not installed (boot.php checks filesize); setup writes into it.
[ -f "$DATA/htconfig.php" ] || { : > "$DATA/htconfig.php"; chmod 600 "$DATA/htconfig.php"; }
cd ~/project
cp htaccess.dist .htaccess
mkdir -p cache/smarty3
