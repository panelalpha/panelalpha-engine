#!/bin/sh
# Tube Archivist refuses to start without an initial login.
missing=""
[ -n "${TA_USERNAME:-}" ] || missing="$missing TA_USERNAME"
[ -n "${TA_PASSWORD:-}" ] || missing="$missing TA_PASSWORD"
if [ -n "$missing" ]; then
    echo "tubearchivist: missing project environment variable(s):$missing. Set TA_USERNAME and TA_PASSWORD (the initial login, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "tubearchivist: initial login set"
