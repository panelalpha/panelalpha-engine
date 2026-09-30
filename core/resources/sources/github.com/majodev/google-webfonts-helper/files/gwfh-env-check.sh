#!/bin/sh
# The server exits at start without a working Google Fonts Developer API key.
help='Create a key for the Google Fonts Developer API (https://developers.google.com/fonts/docs/developer_api), set it as the project environment variable GOOGLE_FONTS_API_KEY and redeploy.'
if [ -z "${GOOGLE_FONTS_API_KEY:-}" ]; then
    echo "google-webfonts-helper: GOOGLE_FONTS_API_KEY is not set. $help" >&2
    exit 1
fi
# Same request the server makes at start; wget exits 8 on an HTTP error.
out=$(wget -q -O - --content-on-error --timeout=15 "https://www.googleapis.com/webfonts/v1/webfonts?key=${GOOGLE_FONTS_API_KEY}" 2>&1)
rc=$?
if [ "$rc" -eq 8 ]; then
    msg=$(printf '%s' "$out" | sed -n 's/.*"message": *"\([^"]*\)".*/\1/p' | head -n 1)
    msg=${msg%.}
    echo "google-webfonts-helper: Google rejected GOOGLE_FONTS_API_KEY: ${msg:-HTTP error}. $help" >&2
    exit 1
fi
[ "$rc" -eq 0 ] || echo "google-webfonts-helper: could not reach www.googleapis.com (wget exit $rc); starting anyway" >&2
echo "google-webfonts-helper: GOOGLE_FONTS_API_KEY set"
