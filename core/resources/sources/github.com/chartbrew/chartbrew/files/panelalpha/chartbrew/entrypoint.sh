#!/bin/bash
# Runtime entrypoint for the official razvanilin/chartbrew image, replacing the
# image's own ./entrypoint.sh. Two jobs it cannot do for itself:
#
#  1. Derive the public origin. Chartbrew bakes the API/client URLs into the Vite
#     bundle at build time (client/src/config/settings.js: API_HOST =
#     VITE_APP_API_HOST). The per-account domain is unknown when the recipe is
#     written, and VITE_APP_API_HOST/VITE_APP_CLIENT_HOST end in _HOST, which the
#     engine's ComposePlaceholders does NOT rewrite (it only touches _URL/_ORIGIN/
#     _ENDPOINT/_DOMAIN). So we take PA_PUBLIC_URL (a _URL key the engine rewrites
#     to the real https origin) and derive the two _HOST values from it here.
#     The client calls the API same-origin under /api (nginx folds the two ports
#     onto one published origin), so VITE_APP_API_HOST = <origin>/api.
#
#  2. Build the client SYNCHRONOUSLY before serving. The image's own entrypoint
#     kicks the rebuild into the background and starts `serve` immediately, which
#     serves the image's build-time dist (localhost API) until the rebuild lands.
#     Building first means `serve` only comes up once dist points at this origin,
#     so the healthcheck gating the deploy never certifies a stale bundle.
set -e

ORIGIN="${PA_PUBLIC_URL:-http://localhost:4018}"
ORIGIN="${ORIGIN%/}"                       # no trailing slash
export VITE_APP_CLIENT_HOST="${ORIGIN}"
export VITE_APP_API_HOST="${ORIGIN}/api"
export VITE_APP_CLIENT_PORT="${VITE_APP_CLIENT_PORT:-4018}"
# Passed through to the AI socket CORS check and any generated links.
export VITE_APP_ONE_ACCOUNT_EXTERNAL_ID="${VITE_APP_ONE_ACCOUNT_EXTERNAL_ID}"

echo "[panelalpha] public origin ${ORIGIN}; API at ${VITE_APP_API_HOST}"

# Backend API (:4019). Runs migrations on boot (server/index.js: db.migrate()).
cd /code/server
NODE_ENV=production node index.js &

# Rebuild the SPA with this origin, then serve it (:4018). Synchronous on purpose.
cd /code/client
echo "[panelalpha] building the UI (this takes a couple of minutes)..."
NODE_ENV=production npm run build
echo "[panelalpha] UI built; serving on :${VITE_APP_CLIENT_PORT}"
exec npx serve -s dist -l "${VITE_APP_CLIENT_PORT}"
