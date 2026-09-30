#!/bin/sh
# One-shot `init`, after the image's docker-setup.sh has written app.ini from the
# environment. Migrates, then creates the admin once; `app` (the only service
# with a published port) starts only after this exits 0.
set -eu

say() { echo "[panelalpha] forgejo init: $*"; }

: "${FORGEJO_ADMIN_USER:?}" "${FORGEJO_ADMIN_PASSWORD:?}" "${FORGEJO_ADMIN_EMAIL:?}"

forgejo migrate

has_admin() {
    forgejo admin user list --admin | awk 'NR > 1 { print $2 }' | grep -qx "${FORGEJO_ADMIN_USER}"
}

if has_admin; then
    say "admin '${FORGEJO_ADMIN_USER}' exists; leaving it alone"
    exit 0
fi

forgejo admin user create --admin --username "${FORGEJO_ADMIN_USER}" \
    --password "${FORGEJO_ADMIN_PASSWORD}" --email "${FORGEJO_ADMIN_EMAIL}" \
    --must-change-password=false >/dev/null
has_admin || { say "admin was not created"; exit 1; }
say "admin '${FORGEJO_ADMIN_USER}' created"
