#!/bin/sh
# Container entrypoint for the app service. One job the image cannot do and the
# repo cannot say: tell Phoenix which public host it answers on.
#
# runtime.exs does `System.get_env("URL_HOST") || System.get_env("HOST") ||
# raise` and builds every URL, redirect and LiveView check_origin from it.
# URL_HOST is a BARE hostname, not a URL, so the engine does not rewrite it
# directly -- but PA_PUBLIC_URL does (its key ends in _URL) and arrives here as
# the account's public https URL. Strip the scheme (and any port/path) to get
# URL_HOST. On an account with no domain yet PA_PUBLIC_URL stays http://localhost
# and URL_HOST=localhost lets the release boot instead of raising.
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"   # drop scheme
host="${host%%/*}"   # drop any path
host="${host%%:*}"   # drop any :port -> bare hostname
[ -n "${host}" ] || host="localhost"

export URL_HOST="${host}"
echo "[panelalpha] URL_HOST=${URL_HOST}"

# The image's own default command, unchanged. /app/bin/server waits for the db
# (pg_isready), runs Mindwendel.Release.migrate, then starts Phoenix on $PORT.
exec /app/bin/server
