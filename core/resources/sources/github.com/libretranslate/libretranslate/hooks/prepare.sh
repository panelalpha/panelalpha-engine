#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Makes sure the .env the compose
# references exists.
#
# LibreTranslate keeps NO user data (stateless translation), so there is no admin
# or user account to seed. The only credential this recipe owns is the managed
# API key used to call /translate programmatically once the compute endpoint is
# gated (LT_REQUIRE_API_KEY_SECRET). It is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env, and keyinit asserts it
# into the key DB on every deploy. The app's own rotating request secret lives
# in Redis and is not our concern.
set -e
cd ~/project

say() { echo "[libretranslate] $*" >&2; }

# The compose libretranslate service lists ~/project/.env as an env_file; make
# sure it exists even when the platform has not written one yet, so `up` does not
# abort on a missing file. The account's env_vars are merged in.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull libretranslate/libretranslate:v1.9.6 >/dev/null 2>&1 || true
say "prepare complete"
