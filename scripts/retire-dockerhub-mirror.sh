#!/usr/bin/env bash
# Removes what the retired `dockerhub-mirror` service left on a host.
#
#   bash scripts/retire-dockerhub-mirror.sh [path/to/.env]
#
# registry-proxy replaced it on the same host port, 127.0.0.1:5001. Its key is
# gone from docker-compose.yml, so a plain `docker compose up -d` leaves the old
# container holding the port and registry-proxy crash-loops, and `down -v`
# never removes its cache volume, which nothing bounds (#87). An .env naming
# the old profile would start no proxy at all. Idempotent: install, update and
# uninstall all run it.
set -u

if ! command -v docker >/dev/null 2>&1; then
    exit 0
fi

# Container: by its fixed name and by compose label, whichever the host has.
ids=$( {
    docker ps -aq --filter 'name=^panelalpha-dockerhub-mirror$'
    docker ps -aq --filter 'label=com.docker.compose.service=dockerhub-mirror'
} 2>/dev/null | sort -u)
if [ -n "$ids" ]; then
    echo "Removing the retired dockerhub-mirror container (registry-proxy replaces it)"
    # shellcheck disable=SC2086
    docker rm -f $ids >/dev/null 2>&1 || true
fi

vols=$(docker volume ls -q --filter 'label=com.docker.compose.volume=dockerhub-mirror-data' 2>/dev/null)
if [ -n "$vols" ]; then
    echo "Removing the retired dockerhub-mirror cache volume: $(echo $vols)"
    # shellcheck disable=SC2086
    docker volume rm -f $vols >/dev/null 2>&1 || true
fi

env_file=${1:-}
if [ -n "$env_file" ] && [ -f "$env_file" ] &&
    grep -Eq '^COMPOSE_PROFILES=(.*,)?dockerhub-mirror(,|$)' "$env_file"; then
    echo "COMPOSE_PROFILES: dockerhub-mirror -> registry-proxy in $env_file"
    sed -i -E '/^COMPOSE_PROFILES=/ { s/(=|,)dockerhub-mirror(,|$)/\1registry-proxy\2/ }' "$env_file"
fi

exit 0
