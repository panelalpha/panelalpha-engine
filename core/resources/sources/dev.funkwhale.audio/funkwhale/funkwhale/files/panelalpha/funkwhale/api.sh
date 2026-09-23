#!/bin/sh
# The web process: gunicorn with the uvicorn worker class, via the image's own
# entrypoint. The account's public name reaches Django as FUNKWHALE_URL, a plain
# container env, so nothing has to be derived here.
set -e
exec /entrypoint.sh gunicorn
