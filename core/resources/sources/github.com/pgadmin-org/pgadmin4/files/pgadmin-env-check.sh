#!/bin/sh
# The image refuses to create its database without an initial admin login.
missing=""
[ -n "${PGADMIN_DEFAULT_EMAIL:-}" ] || missing="$missing PGADMIN_DEFAULT_EMAIL"
[ -n "${PGADMIN_DEFAULT_PASSWORD:-}" ] || missing="$missing PGADMIN_DEFAULT_PASSWORD"
if [ -n "$missing" ]; then
    echo "pgadmin: missing project environment variable(s):$missing. Set PGADMIN_DEFAULT_EMAIL (the admin's email, a real domain) and PGADMIN_DEFAULT_PASSWORD (writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "pgadmin: initial admin login set"
