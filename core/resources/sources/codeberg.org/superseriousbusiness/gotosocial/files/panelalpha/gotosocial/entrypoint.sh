#!/bin/sh
# Entrypoint of the `app` service. GoToSocial's `host` is a bare hostname baked
# into every account/object URI and the instance keypair's key-id, so it must be
# the real public domain. The engine only injects the public address as a full
# https:// URL into PA_PUBLIC_URL (the *_URL placeholder rewrite); this strips it
# down to the host GTS_HOST needs, then exec-s the real server.
#
# Fails closed: no usable host means the deploy fails rather than baking a
# localhost identity into the keypair, which would break federation for good.
set -e

# https://host.example[:port][/path] -> host.example
url="${PA_PUBLIC_URL:-}"
host="${url#*://}"   # strip scheme
host="${host%%/*}"   # strip any path
host="${host%%:*}"   # strip any port

if [ -z "${host}" ] || [ "${host}" = "localhost" ] || [ "${host}" = "127.0.0.1" ]; then
    echo "[panelalpha] gotosocial: no public host resolved from PA_PUBLIC_URL='${url}'; refusing to start with a placeholder identity" >&2
    exit 1
fi

export GTS_HOST="${host}"
echo "[panelalpha] gotosocial: GTS_HOST=${GTS_HOST}" >&2

exec /gotosocial/gotosocial server start
