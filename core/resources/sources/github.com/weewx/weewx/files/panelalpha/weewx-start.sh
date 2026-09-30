#!/bin/sh
# Create the station once on the volume, serve its public_html, run weewxd.
set -e
ROOT=/var/lib/weewx
if [ ! -f "$ROOT/weewx.conf" ]; then
    /app/.venv/bin/weectl station create "$ROOT" --no-prompt
    # No syslog socket in a container: log to stdout.
    printf '\n[Logging]\n    [[root]]\n        handlers = console,\n' >> "$ROOT/weewx.conf"
fi
mkdir -p "$ROOT/public_html"
python -m http.server 8000 --bind 0.0.0.0 --directory "$ROOT/public_html" &
exec /app/.venv/bin/weewxd --config="$ROOT/weewx.conf"
