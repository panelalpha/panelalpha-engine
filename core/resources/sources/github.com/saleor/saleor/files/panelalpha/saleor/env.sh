#!/bin/sh
# Sourced by every Saleor process in this stack -- init.sh, api.sh, worker.sh.
# Everything here is something the image cannot know: which hostname this
# account answers on, and where its keys are.
#
# Not an entrypoint of its own, because the three processes differ only in
# their last line and a value derived twice is a value that can disagree with
# itself.

# ---------------------------------------------------------------------------
# The public name.
#
# PA_PUBLIC_URL arrives as http://localhost in the compose file; the engine
# rewrites it to the account's public https address before the stack starts,
# because the key ends in _URL (ComposePlaceholders::PUBLIC_URL_KEY_PATTERN).
# If that rewrite did not happen -- a project with no public domain yet -- the
# fallback keeps the containers serving on localhost rather than crash-looping.
url="${PA_PUBLIC_URL:-}"
case "${url}" in
    http://localhost|http://localhost/|'') url='' ;;
esac
url="${url%/}"

# Scheme off the front, then everything from the first / or : -- the public URL
# has no path or port, but a hand-set one might.
host=$(printf '%s' "${url}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[/:].*##')

# 127.0.0.1 and localhost are always allowed: nginx's healthcheck asks the
# stack about itself by address, and a healthcheck that 400s would keep the
# deploy's `ready` gate waiting until it gave up.
if [ -n "${host}" ]; then
    default_hosts="${host},localhost,127.0.0.1"
else
    default_hosts="localhost,127.0.0.1"
fi

# settings.py:516. Empty or wrong, Django answers 400 DisallowedHost on every
# path -- the failure the control deploy shows.
if [ -z "${ALLOWED_HOSTS:-}" ]; then
    ALLOWED_HOSTS="${default_hosts}"
fi

# settings.py:103-110 raises ImproperlyConfigured unless this is set once DEBUG
# is off, so it is not optional here. It is a different list from ALLOWED_HOSTS
# and is checked against the redirectUrl a client passes to the account
# mutations -- password reset, email confirmation (saleor/core/utils/url.py:26).
# The API's own domain is the only one this deployment knows; an owner running a
# storefront elsewhere adds its host to ALLOWED_CLIENT_HOSTS in the account's
# env_vars, and that value wins over this one.
if [ -z "${ALLOWED_CLIENT_HOSTS:-}" ]; then
    ALLOWED_CLIENT_HOSTS="${default_hosts}"
fi

# settings.py:202. Saleor builds every absolute URL pointing at itself from
# this, and derives ENABLE_SSL from its scheme (settings.py:209). Left unset,
# it falls back to the Shop.domain row in the database, which is example.com on
# a fresh install.
if [ -n "${url}" ]; then
    PUBLIC_URL="${url}"
else
    unset PUBLIC_URL
fi

# ---------------------------------------------------------------------------
# The keys.
#
# The PEM cannot live in an env file -- compose reads those a line at a time --
# and it cannot arrive on a bind mount either, because a `build:` service with a
# `./` bind makes ComposeFileInspector::isLocalDevCompose() true for the whole
# compose file and the engine then ignores it. So hooks/prepare.sh stores it
# base64-encoded on one line and it is decoded back here.
#
# settings.py:275 takes the PEM text itself, and jwt_manager.py:84 raises
# ImproperlyConfigured without it once DEBUG is off.
if [ -z "${RSA_PRIVATE_KEY:-}" ] && [ -n "${RSA_PRIVATE_KEY_B64:-}" ]; then
    RSA_PRIVATE_KEY="$(printf '%s' "${RSA_PRIVATE_KEY_B64}" | base64 -d)"
fi

# Assembled rather than stored, so the database password is written in exactly
# one file. dj_database_url parses this (settings.py:137).
if [ -z "${DATABASE_URL:-}" ]; then
    DATABASE_URL="postgres://${POSTGRES_USER}:${POSTGRES_PASSWORD}@${POSTGRES_HOST}:${POSTGRES_PORT:-5432}/${POSTGRES_DB}"
fi

export ALLOWED_HOSTS ALLOWED_CLIENT_HOSTS RSA_PRIVATE_KEY DATABASE_URL
[ -n "${PUBLIC_URL:-}" ] && export PUBLIC_URL

echo "[panelalpha] ALLOWED_HOSTS=${ALLOWED_HOSTS} PUBLIC_URL=${PUBLIC_URL:-<unset>} DEBUG=${DEBUG:-<unset>} RSA_PRIVATE_KEY=$([ -n "${RSA_PRIVATE_KEY:-}" ] && echo present || echo MISSING)"
