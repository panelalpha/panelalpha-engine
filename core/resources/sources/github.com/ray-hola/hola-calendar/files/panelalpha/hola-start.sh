#!/bin/sh
# Runs in place of the image's command (python server.py), in /app.
# The Dockerfile COPYs only its own files, so this directory is never in the
# image.

say() { echo "[panelalpha] hola-calendar: $*" >&2; }

if [ -r /pa/app-credentials.env ]; then
    # In a subshell: the password reaches the seeding step, not the server.
    (
        set -a
        . /pa/app-credentials.env
        set +a
        python /panelalpha/hola-login.py
    ) || say "setting the generated password failed; the seeded one stays"
else
    say "no /pa/app-credentials.env; the seeded password stays"
fi

exec python server.py "$@"
