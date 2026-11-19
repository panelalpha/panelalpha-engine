#!/bin/sh
# Runs before php:8.3-apache's own entrypoint. Life OS reads its first sign-in
# from LIFEOS_USERNAME / LIFEOS_PASSWORD and writes it to MySQL (app_credentials,
# then app_users) only while it has none, so a password changed in the app is
# kept. The repository's compose file sets both to "" unless .env has them; here
# they become the login the engine generated.
# The Dockerfile COPYs the whole checkout, but this runs from the mount, so an
# edit here needs no rebuild.
set -e

say() { echo "[panelalpha] lifeos: $*" >&2; }

if [ -r /pa/app-credentials.env ]; then
    set -a
    . /pa/app-credentials.env
    set +a
else
    say "no /pa/app-credentials.env; LIFEOS_USERNAME / LIFEOS_PASSWORD left as they are"
fi

exec docker-php-entrypoint "$@"
