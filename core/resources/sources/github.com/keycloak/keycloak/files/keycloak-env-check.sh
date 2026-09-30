#!/bin/bash
# Without a bootstrap admin the console can only be claimed from localhost.
missing=""
[ -n "${KC_BOOTSTRAP_ADMIN_USERNAME:-}" ] || missing="$missing KC_BOOTSTRAP_ADMIN_USERNAME"
[ -n "${KC_BOOTSTRAP_ADMIN_PASSWORD:-}" ] || missing="$missing KC_BOOTSTRAP_ADMIN_PASSWORD"
if [ -n "$missing" ]; then
    echo "keycloak: missing project environment variable(s):$missing. Set KC_BOOTSTRAP_ADMIN_USERNAME and KC_BOOTSTRAP_ADMIN_PASSWORD (the first admin of the master realm, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "keycloak: bootstrap admin set"
