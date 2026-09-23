#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout runs. It is the Seafile *sync client* daemon: an
# autotools C/Vala tree with no server, no compose file and nothing that serves
# HTTP. The stack is the server image Seafile Ltd publishes, and everything
# this hook writes is a value neither the checkout nor the engine can supply.

# Guarded as a whole: the volumes outlive the checkout. A regenerated
# SEAFILE_MYSQL_DB_PASSWORD would lock seaf-server out of its own schemas, and
# a regenerated admin password would be one the database never learns --
# seahub.sh only reads conf/admin.txt on the boot that creates the superuser.
if [ ! -f .env ]; then
    # Hex, not base64: these values are interpolated by compose, read by a
    # shell and typed by a human, and MariaDB's root password additionally goes
    # through setup-seafile-mysql.py's shell quoting.
    ADMIN_EMAIL=admin@example.com
    ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-20)

    cat > .env <<EOF
SEAFILE_MYSQL_DB_PASSWORD=$(openssl rand -hex 24)
INIT_SEAFILE_MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)
# Signs the tokens seahub hands the file server. Upstream asks for at least 32
# characters; this is 64.
JWT_PRIVATE_KEY=$(openssl rand -hex 32)
INIT_SEAFILE_ADMIN_EMAIL=${ADMIN_EMAIL}
INIT_SEAFILE_ADMIN_PASSWORD=${ADMIN_PASSWORD}
TIME_ZONE=Etc/UTC
EOF
    chmod 600 .env

    # Where the customer is pointed. Seafile has no sign-up page: without a
    # generated admin the site comes up on a login form nobody holds an
    # account for, and upstream's own default is me@example.com / asecret.
    cat > .panelalpha-admin-password <<EOF
# Written by PanelAlpha on the first deploy. Seafile has no sign-up page and no
# installer, so the first administrator is created from these on first boot.
SEAFILE_ADMIN_EMAIL=${ADMIN_EMAIL}
SEAFILE_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    chmod 600 .panelalpha-admin-password
fi

# The image tag, rewritten on every deploy so a redeploy picks up patch
# releases. Deliberately NOT derived from the checkout the way the Ghost and
# paperless recipes derive theirs: configure.ac here says 9.0.21, which is the
# sync client's version and has nothing to do with the server's (13.0 at the
# time of writing). Pinned to the series rather than a floating `latest`
# because `latest` on Docker Hub has not moved since 2025-03 and still points
# at a 12.0 image, and because a major jump needs upgrade scripts that a
# redeploy is not the place to run.
SEAFILE_SERIES="${SEAFILE_SERIES:-13.0}"
sed -i '/^SEAFILE_IMAGE=/d' .env
printf 'SEAFILE_IMAGE=%s\n' "seafileltd/seafile-mc:${SEAFILE_SERIES}-latest" >> .env
