#!/bin/bash
# Runs in the account shell after the clone and after overrides/ are in place,
# before `docker compose up`. A fresh CloudBeaver boots into configuration mode
# and makes the FIRST visitor the admin. CloudBeaver's built-in automatic
# configuration reads CB_SERVER_NAME/CB_ADMIN_NAME/CB_ADMIN_PASSWORD from the
# environment on first boot to seed the admin and mark itself configured. The
# admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env, which the compose file passes to the
# server; this hook only pre-pulls the images.
set -e

say() { echo "[cloudbeaver] $*" >&2; }

# Pre-pull the pinned images so `compose up` starts fast and an image NotFound
# surfaces here (best effort).
docker pull dbeaver/cloudbeaver:26.2.1 >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
say "prepare complete"
