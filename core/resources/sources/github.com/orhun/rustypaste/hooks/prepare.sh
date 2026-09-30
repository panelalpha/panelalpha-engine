#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. The upload and delete tokens
# (AUTH_TOKEN / DELETE_TOKEN) are the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this
# hook; without AUTH_TOKEN rustypaste's upload endpoint is world-writable
# (config.rs). All that is left here is the .env the compose references.
set -e
cd ~/project

say() { echo "[rustypaste] $*" >&2; }

# The compose server service lists ~/project/.env as an env_file; make sure it
# exists even when the platform has not written one yet, so `docker compose up`
# does not abort on a missing file. The account's env_vars are merged in.
touch .env
say "prepare complete"
