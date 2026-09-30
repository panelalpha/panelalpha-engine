#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. The admin password and API key
# (CHHOTO_PASSWORD / CHHOTO_API_KEY) are the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this
# hook; all that is left is making sure the compose env_file exists.
set -e
cd ~/project

say() { echo "[chhoto-url] $*" >&2; }

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even before the platform writes it, so `docker compose up` does not
# abort on a missing file. The account's env_vars are merged in and win.
touch .env
say "prepare complete"
