#!/bin/sh
# Container entrypoint for the app service. One job the image cannot do and the
# repo cannot say: tell Passit which public host it answers on, so the
# confirmation and password-reset links it emails point at this account.
#
# settings.py:83 EMAIL_CONFIRMATION_HOST defaults to http://localhost:4200 and
# is also aliased to HOSTNAME; every email link is built from it. It does not
# end in _URL, so the engine does not rewrite it directly -- but PA_PUBLIC_URL
# does, and arrives here as the account's public https address (or stays
# http://localhost on an account with no domain yet, in which case the image
# default is left in place rather than a broken value).
set -e

url="${PA_PUBLIC_URL:-}"
case "${url}" in
    http://localhost|http://localhost/|'') url='' ;;
esac

if [ -n "${url}" ] && [ -z "${EMAIL_CONFIRMATION_HOST:-}" ]; then
    EMAIL_CONFIRMATION_HOST="${url%/}"
    export EMAIL_CONFIRMATION_HOST
fi

echo "[panelalpha] EMAIL_CONFIRMATION_HOST=${EMAIL_CONFIRMATION_HOST:-<unset: image default http://localhost:4200>}"

# The image's own start script, unchanged: run-granian.sh binds
# 0.0.0.0:$PORT and serves passit.asgi. Migrations were already run by `init`.
cd /code
exec ./bin/start.sh
