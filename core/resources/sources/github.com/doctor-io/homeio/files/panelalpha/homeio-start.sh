#!/bin/sh
# Runs in place of the image's command (sh docker-entrypoint.sh), in /app.
#
# Starts homeio-register.mjs in the background with the login the engine
# generated (read in that subshell only, so the server's environment never has
# it), then runs upstream's docker-entrypoint.sh, which migrates the database
# and execs the server. The helper waits for the server and registers the login
# only while Homeio has no user. The Dockerfile copies only its build output,
# so this directory is never in the image.

say() { echo "[panelalpha] homeio: $*" >&2; }

if [ -r /pa/app-credentials.env ]; then
    (
        set -a
        . /pa/app-credentials.env
        set +a
        exec node /panelalpha/homeio-register.mjs
    ) &
else
    say "no /pa/app-credentials.env; no account registered"
fi

exec sh docker-entrypoint.sh "$@"
