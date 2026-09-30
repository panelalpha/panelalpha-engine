#!/bin/bash
# The README's one-time setup: create the tables, then the admin user.
# Runs once per account; the marker lives on a named volume.
set -e
if [ -f /state/initialized ]; then
    echo "[facto-init] already initialized"
    exit 0
fi
bin/server -DdropAndCreateNewDb
bin/server -DcreateAdminUser
touch /state/initialized
