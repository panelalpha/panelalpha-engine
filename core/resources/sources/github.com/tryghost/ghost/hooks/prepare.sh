#!/bin/bash
set -e
cd ~/project

# The image tag comes from the checkout, not from a constant here: a clone of a
# 6.x branch should get a 6.x Ghost. ghost/core/package.json is the server's own
# manifest and its "version" is the second key in the file, so the first match
# is the one wanted (6.65.0-rc.0 -> 6). Anything unreadable falls back to 6
# rather than to "latest", which would silently jump a major on a checkout that
# never asked for one.
GHOST_MAJOR=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([0-9]\{1,\}\)\..*/\1/p' ghost/core/package.json | head -1)
case "${GHOST_MAJOR}" in
    ''|*[!0-9]*) GHOST_MAJOR=6 ;;
esac

# Guarded: the MySQL data volume outlives the checkout, so regenerating the
# password on a redeploy would lock Ghost out of its own database.
if [ ! -f .env ]; then
    cat > .env <<EOF
GHOST_IMAGE=ghost:${GHOST_MAJOR}-alpine
MYSQL_PASSWORD=$(openssl rand -hex 16)
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF
fi
