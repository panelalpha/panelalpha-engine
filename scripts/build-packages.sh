#!/bin/bash
set -e

cd "$(dirname "$0")"

docker_installed() {
    command -v docker >/dev/null 2>&1
}

if ! docker_installed; then
  echo "Could not detect docker, exiting"
  exit 1
fi

engine_dir="$(cd ".." && pwd)"
core_dir="$engine_dir/core"
force_core_build=false
DEBUG_MODE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
    --debug)
        DEBUG_MODE=1
        shift
        ;;
    --force-core-build)
        force_core_build=true
        shift
        ;;
    --force)
        force_core_build=true
        shift
        ;;
    *)
        shift
        ;;
    esac
done

if [ "$DEBUG_MODE" = 1 ]; then
    set -x
fi

# build core package
if [[ "$force_core_build" = false ]] && [[ -d "$core_dir/vendor" ]]; then
  echo "API package already built, use '--force-core-build' to rebuild"
else
  if [[ -d "$core_dir/vendor" ]]; then
    rm -rf "$core_dir/vendor.bak"
    mv "$core_dir/vendor" "$core_dir/vendor.bak"
  fi
  IMAGE="ghcr.io/panelalpha/engine-composer:v2.0.1"
  # try pull, fallback to build
  if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    if ! docker pull "$IMAGE"; then
      docker build --tag "$IMAGE" - <"$engine_dir/dockerfiles/Dockerfile-composer"
    fi
  fi
  docker run -v "$core_dir:/app" -w /app --rm "$IMAGE" composer install
  echo "API package built"
fi
