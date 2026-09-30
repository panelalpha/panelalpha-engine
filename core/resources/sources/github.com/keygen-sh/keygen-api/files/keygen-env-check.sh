#!/bin/sh
# keygen:setup creates the single account's admin user from these two variables.
missing=""
[ -n "${KEYGEN_ADMIN_EMAIL:-}" ] || missing="$missing KEYGEN_ADMIN_EMAIL"
[ -n "${KEYGEN_ADMIN_PASSWORD:-}" ] || missing="$missing KEYGEN_ADMIN_PASSWORD"
if [ -n "$missing" ]; then
    echo "keygen: missing project environment variable(s):$missing. Set KEYGEN_ADMIN_EMAIL (the admin's email) and KEYGEN_ADMIN_PASSWORD (at least 6 characters, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "keygen: admin email and password set"
