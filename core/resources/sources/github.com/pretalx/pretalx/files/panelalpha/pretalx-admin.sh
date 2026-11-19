#!/bin/sh
# Creates pretalx's first administrator, and the first organiser for it, from
# the login the engine generated (PRETALX_ADMIN_EMAIL / PRETALX_ADMIN_PASSWORD,
# from ~/.panelalpha/app-credentials.env through the compose override's
# env_file), with pretalx's own `init --noinput`: createsuperuser
# (is_administrator, which may create events and see every event) plus an
# organiser with a team holding that user.
#
# Without it the organiser area at /orga/ has a login form and no account: its
# form hides the sign-up, speakers only register through an event's call for
# papers and there is no event, and `init` is a server command.
#
# Runs in the container, from /app, on the install and upgrade stages after
# the django recipe's migrate. Only while there is no administrator at all, so
# an administrator who exists (and a password changed in the app) is never
# touched by a restart or a redeploy.
set -e

PY=.venv/bin/python
MANAGE=src/manage.py

if [ -z "${PRETALX_ADMIN_EMAIL:-}" ] || [ -z "${PRETALX_ADMIN_PASSWORD:-}" ]; then
    echo "[panelalpha] pretalx: PRETALX_ADMIN_EMAIL or PRETALX_ADMIN_PASSWORD is not in the environment; no administrator created" >&2
    exit 1
fi

# Exit 0: an administrator exists; 3: none; anything else: the database could
# not be read, and the step fails rather than guess. pretalx's own `shell`
# refuses to run without an event scope; users belong to no event, so the
# scope is turned off for this one read.
set +e
"$PY" "$MANAGE" shell --unsafe-disable-scopes -c "
import sys
from pretalx.person.models import User
sys.exit(0 if User.objects.filter(is_administrator=True).exists() else 3)
"
status=$?
set -e
if [ "$status" = 0 ]; then
    echo "[panelalpha] pretalx: an administrator exists; left alone" >&2
    exit 0
fi
if [ "$status" != 3 ]; then
    echo "[panelalpha] pretalx: could not read the users table; no administrator created" >&2
    exit 1
fi

# The organiser's name and slug are upstream's own examples (`init --help`);
# both can be changed under /orga/organiser/.
DJANGO_SUPERUSER_EMAIL="$PRETALX_ADMIN_EMAIL" \
DJANGO_SUPERUSER_PASSWORD="$PRETALX_ADMIN_PASSWORD" \
PRETALX_INIT_ORGANISER_NAME="The Conference Organiser" \
PRETALX_INIT_ORGANISER_SLUG="conforg" \
    "$PY" "$MANAGE" init --noinput
echo "[panelalpha] pretalx: created the administrator $PRETALX_ADMIN_EMAIL" >&2
