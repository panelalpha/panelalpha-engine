#!/bin/bash
# Runs in the account shell after the clone and after overrides/ are in place,
# before `docker compose up`. Bytebase has no env/flag to seed the first admin
# (the only server knobs are --data/--port/--external-url), and its setup screen
# makes the FIRST visitor the workspace owner. The `bootstrap` compose service
# claims that owner over Bytebase's own API the instant the server is healthy,
# before any visitor can, with the login the engine generated (`credentials:` in
# panelalpha.yaml, in ~/.panelalpha/app-credentials.env). This hook only
# pre-pulls the images.
set -e

say() { echo "[bytebase] $*" >&2; }

# Pre-pull the pinned images so `compose up` starts fast and an image NotFound
# surfaces here (best effort).
docker pull bytebase/bytebase:3.23.0 >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
say "prepare complete"
