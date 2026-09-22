#!/bin/bash
# App-service entrypoint. One job the image cannot do and the repo cannot say:
# tell PieFed which public host it answers on. config.py reads SERVER_NAME as a
# BARE hostname and builds every actor/URL from it, so the engine does not
# rewrite it directly -- but PA_PUBLIC_URL does (its key ends in _URL) and
# arrives as the account's public https URL. Strip the scheme to get the host.
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"      # drop scheme
host="${host%%/*}"      # drop any path/trailing slash
[ -n "${host}" ] || host="localhost"

export SERVER_NAME="${host}"
echo "[panelalpha] pyfedi: SERVER_NAME=${SERVER_NAME}"

# The image's own entrypoint (tini + flask db upgrade + populate_community_search
# + gunicorn), unchanged, with SERVER_NAME now in the environment it inherits.
cd /app
exec /usr/bin/tini -- /app/entrypoint.sh
