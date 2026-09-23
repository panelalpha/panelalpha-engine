#!/bin/sh
# Container entrypoint for the app service. One job the image cannot do and the
# repo cannot say: tell Phoenix which public host it answers on.
#
# runtime.exs does `System.get_env("HOST") || raise` and builds every URL,
# redirect and LiveView check_origin from it. HOST is a BARE hostname, not a URL,
# so the engine does not rewrite it directly -- but PA_PUBLIC_URL does (its key
# ends in _URL) and arrives here as the account's public https URL. Strip the
# scheme to get HOST. On an account with no domain yet PA_PUBLIC_URL stays
# http://localhost, and HOST=localhost lets the release boot instead of raising
# (links point at localhost until a domain is attached).
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
# strip scheme and any path/trailing slash -> bare host[:port]
host="${url#*://}"
host="${host%%/*}"
[ -n "${host}" ] || host="localhost"

export HOST="${host}"
echo "[panelalpha] HOST=${HOST}"

# The image's own default command, unchanged. runtime.exs sets the endpoint
# server:true in prod and automigrate:true, so bin/cannery start runs migrations
# in-supervisor and serves on :$PORT.
cd /app
exec bin/cannery start
