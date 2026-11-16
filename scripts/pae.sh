#!/usr/bin/env bash

cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.."

# `docker compose exec` does not pass the host's environment, so the migrate secrets are named.
exec docker compose exec \
    -e "WORDPRESS_MIGRATE_PASSWORD=${WORDPRESS_MIGRATE_PASSWORD-}" \
    -e "WORDPRESS_MIGRATE_SOURCE_TOKEN=${WORDPRESS_MIGRATE_SOURCE_TOKEN-}" \
    core php artisan "$@"
