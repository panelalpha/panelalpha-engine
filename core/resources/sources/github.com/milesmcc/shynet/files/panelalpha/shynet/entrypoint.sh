#!/bin/bash
# Container entrypoint for the web service. One job: tell Shynet which
# hostname this account answers on, which is the one thing the image cannot
# know and the repository cannot say.
#
# shynet/settings.py:43 reads
#   ALLOWED_HOSTS = (os.getenv("ALLOWED_HOSTS") or "localhost,127.0.0.1").split(",")
# with DEBUG off, so an unset ALLOWED_HOSTS makes Django answer 400
# DisallowedHost to every request on every path that does not arrive as
# localhost. That is not a broken page; it is a site that never renders once.
#
# The value arrives as PA_PUBLIC_URL, which the compose file sets to
# http://localhost and the engine rewrites to the account's public https
# address before the stack starts. If that rewrite did not happen -- a project
# with no public domain yet -- the fallback keeps the container serving on
# localhost instead of crash-looping.
set -e

url="${PA_PUBLIC_URL:-}"
case "${url}" in
    http://localhost|http://localhost/|'') url='' ;;
esac

scheme=$(printf '%s' "${url}" | sed -n 's#^\([a-zA-Z][a-zA-Z0-9+.-]*\)://.*#\1#p')
# Scheme off the front, then everything from the first / or : -- the public
# URL has no path or port, but a hand-set one might.
host=$(printf '%s' "${url}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/:].*##')

# 127.0.0.1 and localhost are always allowed: the compose healthcheck asks the
# container about itself by address, and a healthcheck answered 400 would keep
# the deploy's `ready` gate waiting until it gave up.
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
# Django 4 compares the Origin header of every POST against the request's own
# scheme and host. The account is https at the edge and plain http inside the
# container, and Shynet sets no SECURE_PROXY_SSL_HEADER, so the two disagree
# and the login form itself is rejected with 403 unless the origin is named
# outright. settings.py:44 is what CSRF_TRUSTED_ORIGINS is read from.
if [ -z "${CSRF_TRUSTED_ORIGINS:-}" ] && [ -n "${default_origins}" ]; then
    CSRF_TRUSTED_ORIGINS="${default_origins}"
fi
# The tracking snippet the dashboard hands the operator is built as
# {{script_protocol}}{{request.get_host}}... (dashboard/views.py:68,
# templates/dashboard/includes/service_snippet.html), and script_protocol is
# this setting, not the request's scheme. Left at its default True on an
# http-only account it would hand out an https URL that does not answer.
if [ -z "${SCRIPT_USE_HTTPS:-}" ] && [ -n "${scheme}" ]; then
    if [ "${scheme}" = "https" ]; then SCRIPT_USE_HTTPS=True; else SCRIPT_USE_HTTPS=False; fi
fi
export ALLOWED_HOSTS CSRF_TRUSTED_ORIGINS SCRIPT_USE_HTTPS

echo "[panelalpha] ALLOWED_HOSTS=${ALLOWED_HOSTS} CSRF_TRUSTED_ORIGINS=${CSRF_TRUSTED_ORIGINS:-} SCRIPT_USE_HTTPS=${SCRIPT_USE_HTTPS:-}"

# The image's own entrypoint, unchanged. With PERFORM_CHECKS_AND_SETUP=False
# it goes straight to webserver.sh; the migrations it would otherwise run here
# have already been run by the `init` service, before this container was
# allowed to start.
cd /usr/src/shynet
exec ./entrypoint.sh
