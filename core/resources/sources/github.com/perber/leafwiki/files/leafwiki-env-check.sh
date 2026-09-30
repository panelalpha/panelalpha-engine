#!/bin/sh
# LeafWiki exits at start without an initial admin password.
if [ -z "${LEAFWIKI_ADMIN_PASSWORD:-}" ]; then
    echo "leafwiki: missing project environment variable(s): LEAFWIKI_ADMIN_PASSWORD. Set it to the initial admin password (writing any \$ as \$\$; LEAFWIKI_ADMIN_USERNAME is optional, default admin), then redeploy." >&2
    exit 1
fi
echo "leafwiki: initial admin password set"
