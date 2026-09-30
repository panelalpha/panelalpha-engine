#!/bin/sh
# YOURLS reads its only admin login from these; without them nobody can sign in.
missing=""
[ -n "${YOURLS_USER:-}" ] || missing="$missing YOURLS_USER"
[ -n "${YOURLS_PASS:-}" ] || missing="$missing YOURLS_PASS"
if [ -n "$missing" ]; then
    echo "yourls: missing project environment variable(s):$missing. Set YOURLS_USER (the admin username) and YOURLS_PASS (its password, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "yourls: admin login set"
