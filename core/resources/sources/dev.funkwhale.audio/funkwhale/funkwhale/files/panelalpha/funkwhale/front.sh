#!/bin/sh
# The front nginx. Its config template needs FUNKWHALE_HOSTNAME and
# FUNKWHALE_PROTOCOL (for the /manifest.json redirect and served links), which
# only exist once PA_PUBLIC_URL has been rewritten. Derive them, then hand off
# to the image's own nginx entrypoint so the standard envsubst-on-templates
# step substitutes them into /etc/nginx/conf.d/default.conf.
set -e
# shellcheck disable=SC1091
. /srv/pa/env.sh
exec /docker-entrypoint.sh nginx -g 'daemon off;'
