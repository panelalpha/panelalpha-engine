#!/bin/sh
# SillyTavern refuses to listen publicly without protection; the recipe uses its
# basic-auth mode, so both credentials must come from the project's env vars.
missing=""
[ -n "${SILLYTAVERN_BASICAUTHUSER_USERNAME:-}" ] || missing="$missing SILLYTAVERN_BASICAUTHUSER_USERNAME"
[ -n "${SILLYTAVERN_BASICAUTHUSER_PASSWORD:-}" ] || missing="$missing SILLYTAVERN_BASICAUTHUSER_PASSWORD"
if [ -n "$missing" ]; then
    echo "sillytavern: missing project environment variable(s):$missing. SillyTavern only listens publicly behind its basic authentication; set the login you want and redeploy." >&2
    exit 1
fi
echo "sillytavern: basic-auth credentials set"
