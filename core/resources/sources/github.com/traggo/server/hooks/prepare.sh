#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. The first admin's login
# (TRAGGO_DEFAULT_USER_NAME / TRAGGO_DEFAULT_USER_PASS) is the engine's
# (`credentials:` in panelalpha.yaml), written to ~/.panelalpha/app-credentials.env
# before this hook; all that is left is the .env the compose references.
set -e
cd ~/project

say() { echo "[traggo] $*" >&2; }

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even when the platform has not written one yet, so `docker compose up`
# does not abort on a missing file. The account's env_vars are merged in.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull traggo/server:0.8.3 >/dev/null 2>&1 || true
say "prepare complete"
