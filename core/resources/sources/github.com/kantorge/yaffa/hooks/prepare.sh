#!/bin/sh
# Runs after the clone, before the build, in the checkout, as the account user.
set -e

# The mysql shim (files/pa-bin/mysql) must be executable for Laravel's schema
# loader to invoke it as a program; file snippets are written non-executable.
if [ -f pa-bin/mysql ]; then
    chmod +x pa-bin/mysql
    echo "yaffa: made pa-bin/mysql executable"
fi

# YAFFA keeps uploads (receipts, imported files, AI documents) under
# storage/app. The checkout is wiped on every redeploy (engine#173), so create
# the persistent, rebuild-surviving directory the compose override binds there
# (relative to ~/project, i.e. ~/.panelalpha/yaffa/storage-app) before Docker
# would create it root-owned. storage/app/public is Laravel's public disk,
# which storage:link points public/storage at.
mkdir -p ../.panelalpha/yaffa/storage-app/public
echo "yaffa: persistent storage/app ready"
