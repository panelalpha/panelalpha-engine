#!/bin/bash
# PanelAlpha wrapper for the LinuxServer babybuddy image. Runs as PID1 (root) in
# front of the image's own s6 init (/init). Its one job is the single thing the
# image cannot know: which https origin this account answers on, which Django 4+
# demands in CSRF_TRUSTED_ORIGINS or it rejects every POST -- the login form
# included -- with 403.
#
# The value arrives as PA_PUBLIC_URL. The compose file sets it to
# http://localhost:8000, which the engine rewrites to the account's public https
# address before the stack starts, because the key ends in _URL
# (ComposePlaceholders::PUBLIC_URL_KEY_PATTERN). s6-overlay copies this process's
# environment into the container_environment that every `with-contenv` service
# reads, so exporting CSRF_TRUSTED_ORIGINS here reaches gunicorn.
set -e

url="${PA_PUBLIC_URL:-}"
# The unrewritten placeholder means no public domain yet: leave CSRF unset
# rather than trust localhost. The app still serves; only cross-origin POSTs
# over a real domain would need the value, and there is no real domain yet.
case "${url}" in
    http://localhost|http://localhost/|http://localhost:8000|http://localhost:8000/|'') url='' ;;
esac

# An operator who set CSRF_TRUSTED_ORIGINS in the account's env_vars means it;
# only fill in what is empty.
if [ -z "${CSRF_TRUSTED_ORIGINS:-}" ] && [ -n "${url}" ]; then
    export CSRF_TRUSTED_ORIGINS="${url%/}"
fi

echo "[panelalpha] CSRF_TRUSTED_ORIGINS=${CSRF_TRUSTED_ORIGINS:-<unset: no public domain yet>}"

# Hand off to the image's own init unchanged (s6 sets up the abc user from
# PUID/PGID, runs migrate, then starts nginx + gunicorn).
exec /init
