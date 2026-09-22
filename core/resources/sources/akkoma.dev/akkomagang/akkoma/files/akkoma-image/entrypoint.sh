#!/bin/sh
# App container entrypoint. One job the image cannot know: which public host
# Akkoma answers on. config/docker.exs reads DOMAIN and bakes it into every
# actor URI, redirect and federation identity. DOMAIN is a BARE hostname, not a
# URL, so the engine does not rewrite it directly -- but PA_PUBLIC_URL does (its
# key ends in _URL) and arrives as the account's public https URL. Strip the
# scheme to get DOMAIN. With no domain yet, PA_PUBLIC_URL stays http://localhost
# and DOMAIN=localhost lets the release boot instead of raising.
#
# The domain is baked into federation actor URIs the first time a status is
# signed, so it must be stable after first boot -- and it is: the account's
# public host does not change across redeploys.
set -e

url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"      # strip scheme
host="${host%%/*}"      # strip any path
host="${host%%:*}"      # strip any :port
[ -n "${host}" ] || host="localhost"

export DOMAIN="${host}"
: "${ADMIN_EMAIL:=admin@${host}}"; export ADMIN_EMAIL
: "${NOTIFY_EMAIL:=${ADMIN_EMAIL}}"; export NOTIFY_EMAIL

echo "[panelalpha] DOMAIN=${DOMAIN} ADMIN_EMAIL=${ADMIN_EMAIL}"

# The official release binary, foreground. Migrations already ran in `init`;
# `start` does not migrate, it just serves on :4000 (config/docker.exs
# http: [ip: {0,0,0,0}, port: 4000]).
cd /opt/akkoma
exec bin/pleroma start
