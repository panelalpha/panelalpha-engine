#!/bin/sh
# Derives the public subpath URLs the SPA's browser code talks to from
# PA_PUBLIC_URL (rewritten to the account's https origin), then runs the image's
# own entrypoint chain, which envsubsts them into the static config.js the
# browser loads (window.srConfig.API_URL / MEILISEARCH_URL). Both are
# same-origin subpaths of this container's nginx (see salt-rim.conf), so the
# browser makes no cross-origin calls. A compose `entrypoint:` clears the image
# CMD, so the chain (nginxinc /docker-entrypoint.sh + the image's
# /usr/local/bin/entrypoint) is reproduced here explicitly.
set -e

ORIGIN="${PA_PUBLIC_URL:-http://localhost}"
ORIGIN="${ORIGIN%/}"
export API_URL="${ORIGIN}/bar"
export MEILISEARCH_URL="${ORIGIN}/search"
export ALLOW_REGISTRATION="${ALLOW_REGISTRATION:-false}"
echo "[panelalpha] API_URL=${API_URL} MEILISEARCH_URL=${MEILISEARCH_URL}"

exec /docker-entrypoint.sh /usr/local/bin/entrypoint
