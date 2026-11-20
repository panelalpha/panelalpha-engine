#!/bin/bash
# Puts the engine's login into Rundeck's realm.properties while it is still the one
# the image ships (admin / admin and user / user), then runs the image's own
# entrypoint. The file is in the image, not on a volume, so every new container
# starts from the shipped one and gets the same stored login again; Rundeck has no
# page to change a realm.properties password, and a file edited by hand (no default
# admin line left) is never touched.
set -eu
REALM=/home/rundeck/server/config/realm.properties
if [ -n "${RUNDECK_ADMIN_PASSWORD:-}" ] && grep -qx 'admin:admin,user,admin' "$REALM"; then
    name="${RUNDECK_ADMIN_USERNAME:-admin}"
    # Jetty's MD5: credential, so the file holds no plain password.
    hash="$(printf '%s' "$RUNDECK_ADMIN_PASSWORD" | md5sum | cut -d' ' -f1)"
    sed -i -e '/^user:user,user$/d' \
        -e "s|^admin:admin,user,admin\$|${name}:MD5:${hash},user,admin|" "$REALM"
    echo "[panelalpha] rundeck: the admin's login is the one the engine generated" >&2
fi
unset RUNDECK_ADMIN_USERNAME RUNDECK_ADMIN_PASSWORD
exec docker-lib/entry.sh "$@"
