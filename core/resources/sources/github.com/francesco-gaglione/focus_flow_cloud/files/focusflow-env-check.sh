#!/bin/sh
# Registration is admin-only: the first admin comes from these two variables.
missing=""
[ -n "$ADMIN_USERNAME" ] || missing="$missing ADMIN_USERNAME"
[ -n "$ADMIN_PASSWORD" ] || missing="$missing ADMIN_PASSWORD"
if [ -n "$missing" ]; then
    echo "Focus Flow needs these project environment variables (the first admin login):$missing" >&2
    exit 1
fi
