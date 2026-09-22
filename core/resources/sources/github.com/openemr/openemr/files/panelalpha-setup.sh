#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy.
#
# The name is load-bearing. OpenEMR serves from the repository root, so this
# file sits inside the document root -- and the generated vhost denies
# `^(?:docker-compose\.ya?ml|panelalpha[-.])` outright, which is why it is
# `panelalpha-setup.sh` at the top rather than `panelalpha/setup.sh` one level
# down. A directory of that name would be served.
set -e
cd /app

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[openemr] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# 512M, and it is not the schema that needs it: Installer::load_file() streams
# each dump a line at a time, so the 23.6 MB language pack never lands in
# memory. It is Installer::install_gacl(), which builds the whole ACL tree
# through the GaclApi in one process, and insert_globals(), which requires
# library/globals.inc.php -- the full globals metadata table. The stock CLI
# limit has been enough in testing; this is headroom, not a measured need.
#
# No `timeout` on the command in panelalpha.yaml. The measured install is about
# 30s here, but the language pack is 237,511 separate INSERT round trips and a
# host under load could stretch that a long way; AppLauncher already gives the
# whole `docker compose up -d` 3600s (COMPOSE_TIMEOUT_SECONDS), which is the
# budget that should decide it.
php -d memory_limit=512M panelalpha-install.php
