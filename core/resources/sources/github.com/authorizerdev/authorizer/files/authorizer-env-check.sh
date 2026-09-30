#!/bin/sh
# Authorizer v2 refuses to start without an admin secret (the dashboard login).
if [ -z "${AUTHORIZER_ADMIN_SECRET:-}" ]; then
    echo "authorizer: missing project environment variable AUTHORIZER_ADMIN_SECRET. Set it to the password for the admin dashboard (/dashboard), then redeploy." >&2
    exit 1
fi
echo "authorizer: admin secret set"
