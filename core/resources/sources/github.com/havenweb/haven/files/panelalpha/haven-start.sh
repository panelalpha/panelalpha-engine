#!/bin/bash
# Runs in place of the image's command (bash ./bin/docker-start).
#
# bin/docker-start creates the first admin with
#   bin/rails r ./deploymentscripts/lib/ruby/create_user.rb "$HAVEN_USER_EMAIL" $HAVEN_USER_PASS
# and the repository's docker-compose.yml sets those two to its published
# changeme@havenweb.org / ChangeMeN0W. Here they become the login the engine
# generated. create_user.rb creates a user only while none has that email, so
# a password changed in Haven is never reset, and the published login is never
# created.
set -e

say() { echo "[panelalpha] haven: $*" >&2; }

if [ -r /pa/app-credentials.env ]; then
    set -a
    . /pa/app-credentials.env
    set +a
fi

if [ -n "${HAVEN_ADMIN_EMAIL:-}" ] && [ -n "${HAVEN_ADMIN_PASSWORD:-}" ]; then
    HAVEN_USER_EMAIL="${HAVEN_ADMIN_EMAIL}"
    HAVEN_USER_PASS="${HAVEN_ADMIN_PASSWORD}"
    export HAVEN_USER_EMAIL HAVEN_USER_PASS
else
    say "HAVEN_ADMIN_EMAIL or HAVEN_ADMIN_PASSWORD is empty; bin/docker-start keeps the compose file's login"
fi
unset HAVEN_ADMIN_EMAIL HAVEN_ADMIN_PASSWORD

cd /app
exec bash ./bin/docker-start "$@"
