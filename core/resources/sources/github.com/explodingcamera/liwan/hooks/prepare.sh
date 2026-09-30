#!/bin/bash
# Account shell, after the clone and after files/ + overrides have been written,
# before the build. The administrator login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env; this hook only checks
# the checkout and pre-pulls the base images.
set -e
cd ~/project

say() { echo "[liwan] $*" >&2; }

if [ ! -f Cargo.toml ] || [ ! -f src/cli.rs ]; then
    say "WARNING: this does not look like explodingcamera/liwan"
fi

# Pull the wrapper's base images now, through the account's nested daemon, so
# the build is not the slow path. Best effort.
docker pull ghcr.io/explodingcamera/liwan:latest >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
