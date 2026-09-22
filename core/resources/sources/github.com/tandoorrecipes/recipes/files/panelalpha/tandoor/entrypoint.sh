#!/bin/sh
# Container entrypoint for the web service. Everything here is one thing the
# image cannot know: which hostname this account answers on.
#
# recipes/settings.py:125 reads ALLOWED_HOSTS from the environment with an
# empty default, and DEBUG is 0, so an unset ALLOWED_HOSTS makes Django answer
# 400 DisallowedHost to every request on every path. Nothing in the image
# fixes that; nothing in the repository can, because the name is chosen when
# the account is created.
#
# The value arrives as PA_PUBLIC_URL. The compose file sets it to
# http://localhost, which the engine rewrites to the account's public https
# address before the stack starts, because the key ends in _URL
# (ComposePlaceholders::PUBLIC_URL_KEY_PATTERN). If that rewrite did not
# happen -- a project with no public domain yet -- the fallback below keeps
# the container serving on localhost rather than crash-looping.
set -e

url="${PA_PUBLIC_URL:-}"
case "${url}" in
    http://localhost|http://localhost/|'') url='' ;;
esac

# Scheme off the front, then everything from the first / or : -- the public
# URL has no path or port, but a hand-set one might.
host=$(printf '%s' "${url}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/:].*##')

# 127.0.0.1 and localhost are always allowed: the compose healthcheck asks the
# container about itself by address, and a healthcheck that 400s would keep the
# deploy's `ready` gate waiting forever.
if [ -n "${host}" ]; then
    default_hosts="${host},localhost,127.0.0.1"
    default_origins="${url%/}"
else
    default_hosts="localhost,127.0.0.1"
    default_origins=""
fi

# An operator who set either of these in the account's env_vars means it; only
# fill in what is empty.
if [ -z "${ALLOWED_HOSTS:-}" ]; then
    ALLOWED_HOSTS="${default_hosts}"
fi
# Django 4+ compares the Origin header against the request's own scheme and
# host. The account is https at the edge and http inside, so unless the proxy's
# X-Forwarded-Proto is honoured on every hop the two disagree and every POST --
# including the login form -- is rejected with 403. Naming the origin outright
# is what settings.py:126 is for, and is the difference between a site you can
# read and a site you can use.
if [ -z "${CSRF_TRUSTED_ORIGINS:-}" ] && [ -n "${default_origins}" ]; then
    CSRF_TRUSTED_ORIGINS="${default_origins}"
fi
export ALLOWED_HOSTS CSRF_TRUSTED_ORIGINS

echo "[panelalpha] ALLOWED_HOSTS=${ALLOWED_HOSTS} CSRF_TRUSTED_ORIGINS=${CSRF_TRUSTED_ORIGINS:-}"

# The image's own entrypoint, unchanged, tini included -- gunicorn's workers
# need a reaper and boot.sh does not provide one.
cd /opt/recipes
exec /sbin/tini -- /opt/recipes/boot.sh
