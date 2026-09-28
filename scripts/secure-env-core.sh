#!/bin/bash
#
# .env-core holds APP_KEY, the core database password and whatever `pae
# configure` stored. `cp -n` under the default umask left it 0644, readable by
# every user on the host. Core's php-fpm, queue workers and scheduler read it
# as www-data -- uid and gid 33 in the core image -- so the group keeps read
# access; 0600 would take the engine down.
#
#   bash scripts/secure-env-core.sh /opt/panelalpha/shared-hosting/.env-core
#
# Idempotent. Also covers the rolling `.pae-backup` copy `pae configure`
# writes beside the file inside the container.

set -euo pipefail

CORE_GID=33

secure() {
    local file="$1"
    [ -f "$file" ] || return 0

    if [ "$(id -u)" -eq 0 ]; then
        chown "root:${CORE_GID}" "$file"
    elif [ "$(stat -c %g "$file")" != "$CORE_GID" ]; then
        # Without root the group cannot be set, and dropping the world bit
        # alone would lock www-data out. Leave it; the installer runs as root.
        echo "secure-env-core: not root, leaving ${file} as it is" >&2
        return 0
    fi
    chmod 0640 "$file"
}

env_core="${1:?usage: secure-env-core.sh <path to .env-core>}"
secure "$env_core"
secure "$(dirname "$env_core")/core/.env.pae-backup"
