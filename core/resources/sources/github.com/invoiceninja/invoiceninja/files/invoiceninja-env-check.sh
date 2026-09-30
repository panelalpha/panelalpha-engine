#!/bin/sh
# The image's init.sh creates the first account from these and exits without them.
missing=""
[ -n "${IN_USER_EMAIL:-}" ] || missing="$missing IN_USER_EMAIL"
[ -n "${IN_PASSWORD:-}" ] || missing="$missing IN_PASSWORD"
if [ -n "$missing" ]; then
    echo "invoiceninja: missing project environment variable(s):$missing. Set IN_USER_EMAIL and IN_PASSWORD (the first account's login, writing any \$ as \$\$), then redeploy." >&2
    exit 1
fi
echo "invoiceninja: first account login set"
