#!/bin/sh
# Cleanarr is a front end for one Plex server and needs its address and token
# (README: both "required"). Fail the deploy naming each one that is missing.
missing=""
case "${PLEX_BASE_URL:-}" in ""|*yourip*) missing="$missing PLEX_BASE_URL" ;; esac
case "${PLEX_TOKEN:-}" in ""|yourplextoken) missing="$missing PLEX_TOKEN" ;; esac

if [ -n "$missing" ]; then
    echo "cleanarr: set in the project's environment variables:$missing (your Plex server address, e.g. http://203.0.113.5:32400, and a Plex token), then redeploy. LIBRARY_NAMES (default \"Movies\") is optional." >&2
    exit 1
fi
echo "cleanarr: Plex settings present"
