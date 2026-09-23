#!/bin/sh
# Container entrypoint for the app service. Two jobs the image cannot do and the
# repo cannot say: tell Phoenix which public host it answers on, and give it a
# from-address on that host.
#
# config/docker.exs reads MOBILIZON_INSTANCE_HOST (a BARE hostname, default
# mobilizon.lan) and builds every URL, redirect, federation actor id and
# LiveView/subscription check from it. HOST is not a URL, so the engine does not
# rewrite it directly -- but PA_PUBLIC_URL does (its key ends in _URL) and
# arrives here as the account's public https URL. Strip the scheme to get HOST.
# On an account with no domain yet PA_PUBLIC_URL stays http://localhost and
# HOST=localhost lets the release boot instead of running on mobilizon.lan.
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
# strip scheme and any path/trailing slash -> bare host[:port]
host="${url#*://}"
host="${host%%/*}"
[ -n "${host}" ] || host="localhost"

export MOBILIZON_INSTANCE_HOST="${host}"

# email_from defaults to noreply@mobilizon.lan; point it at this account's host
# unless the operator set one. Only used when SMTP is configured.
if [ -z "${MOBILIZON_INSTANCE_EMAIL:-}" ]; then
    export MOBILIZON_INSTANCE_EMAIL="noreply@${host}"
fi

echo "[panelalpha] MOBILIZON_INSTANCE_HOST=${MOBILIZON_INSTANCE_HOST} MOBILIZON_INSTANCE_EMAIL=${MOBILIZON_INSTANCE_EMAIL}"

# The image's own entrypoint, unchanged: it waits for the database, creates the
# pg_trgm/unaccent extensions, runs `mobilizon_ctl migrate` (a no-op now that
# init has already migrated) and execs `mobilizon start` on :$MOBILIZON_INSTANCE_PORT.
exec /docker-entrypoint.sh
