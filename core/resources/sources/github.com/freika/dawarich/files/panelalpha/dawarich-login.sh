#!/bin/sh
# Command of the web service. The image's web-entrypoint.sh migrates, runs
# db:seed (a new database gets the admin demo@dawarich.app / safepassword) and
# then runs this under `bundle exec`: while an admin still has that seeded
# password, it gets the engine's email and password (dawarich-login.rb, through
# Dawarich's own User model). Then the image's command (the Rails server) runs.
if [ -n "${DAWARICH_ADMIN_PASSWORD:-}" ]; then
    bin/rails runner /panelalpha/dawarich-login.rb \
        || echo "[panelalpha] dawarich: could not set the admin login; it stays demo@dawarich.app / safepassword" >&2
fi
exec "$@"
