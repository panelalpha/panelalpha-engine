#!/bin/sh
# Derive lesma's link config from the account public URL the engine injects, then
# exec the untouched upstream binary. lesma builds every paste URL against the
# LESMA_DOMAINS whitelist and returns an EMPTY link for a Host not on it, so the
# account host must be present; and it needs LESMA_HTTPS=true to emit https links.
# Neither can be baked into the image -- the domain is unknown until deploy.
# ComposePlaceholders rewrites PA_PUBLIC_URL (key ends _URL, localhost value) to
# https://<account-domain>; parse it here.
set -e

url="${PA_PUBLIC_URL:-http://localhost:8000}"
scheme="${url%%://*}"
hostport="${url#*://}"
hostport="${hostport%%/*}"        # drop any path
[ -n "$hostport" ] || hostport="localhost:8000"

if [ "$scheme" = "https" ]; then
  export LESMA_HTTPS=true
else
  export LESMA_HTTPS=false
fi

# Public host first, then the loopback names the in-container healthcheck and any
# direct-IP hit use, so those requests also generate valid links. Unquoted array
# is the figment form the upstream README documents and it parses cleanly.
export LESMA_DOMAINS="[${hostport}, localhost:8000, 127.0.0.1:8000]"

exec /bin/lesma
