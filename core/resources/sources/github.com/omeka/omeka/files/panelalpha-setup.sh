#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy.
#
# The name is load-bearing. Omeka Classic serves from the repository root, so
# this file sits inside the document root -- and the generated vhost denies
# `^(?:docker-compose\.ya?ml|panelalpha[-.])` outright, which is why it is
# `panelalpha-setup.sh` at the top rather than `panelalpha/setup.sh` one level
# down. That denial matches a file name, not a path component, so a directory
# of that name would be served.
set -e
cd /app

# ---------------------------------------------------------------------------
# 1. db.ini, from the database the engine provisioned.
#
# `database: mysql` in panelalpha.yaml is what asks for it: AppDatabase creates
# a database and user on the account's own MySQL server -- visible in the
# panel, openable in phpMyAdmin, included in the account's backup -- and the
# generated compose file passes the credentials as DB_*. This is the only place
# they can be written: hooks/prepare.sh runs before the database exists.
#
# Omeka is MySQL through mysqli and nothing else. Omeka_Application_Resource_Db
# calls `Zend_Db::factory('Mysqli', ...)` with no alternative, and
# Installer_Requirements::_checkMysqliIsAvailable() refuses to install without
# the extension. `charset = "utf8"` is upstream's own default in
# db.ini.changeme and the resource rewrites it to utf8mb4 on the way to the
# adapter; `prefix` is the table-name prefix every Omeka_Db query builds on.
#
# Rewritten whenever it does not match, not only when it is missing. The
# credentials are stable across redeploys (AppDatabase keeps the password in
# the account's encrypted details precisely so a redeploy cannot invalidate a
# config file), but a restored backup or a hand-edited file that points at
# nothing is a 500 on every request, and the values the container was handed
# are by definition the ones that work.
#
# 0600 because it holds a password. The container runs as the account uid --
# the compose file says `user: "<uid>:<gid>"` -- so Apache is the owner and can
# read it. The .htaccess hooks/prepare.sh installs denies `.ini` to the web as
# well; both, because either one alone is a single edit away from publishing
# the account's MySQL password.
ini_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

write_db_ini() {
    ( umask 077; cat > db.ini <<EOF
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Database Configuration File ;
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
;
; Written by PanelAlpha on every deploy from the credentials of the database
; provisioned for this account. Edit application/config/config.ini for
; anything else; this file is regenerated.

[database]
host     = "$(ini_escape "${DB_HOST}")"
username = "$(ini_escape "${DB_USERNAME}")"
password = "$(ini_escape "${DB_PASSWORD}")"
dbname   = "$(ini_escape "${DB_DATABASE}")"
prefix   = "omeka_"
charset  = "utf8"
port     = "$(ini_escape "${DB_PORT:-3306}")"
EOF
    )
    chmod 600 db.ini
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[omeka] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

if ! grep -q "^dbname *= *\"${DB_DATABASE}\"$" db.ini 2>/dev/null \
   || ! grep -q "^host *= *\"${DB_HOST}\"$" db.ini 2>/dev/null; then
    write_db_ini
    echo "[omeka] wrote db.ini for ${DB_DATABASE}@${DB_HOST}"
fi

# ---------------------------------------------------------------------------
# 2. Install, or migrate.
#
# See panelalpha-install.php: on a database with no `omeka_options` table it
# runs Omeka's own Installer_Default; on one that has it, the migrations
# UpgradeController would run in the browser. The second is not optional --
# Omeka_Controller_Plugin_Upgrade answers every public request with
# `die("Public site is unavailable until the upgrade completes.")` while a
# migration is pending, so a code change that ships one would take the site
# down until an administrator found /admin/upgrade.
#
# 512M because the installer creates the schema, the element sets and the
# default navigation in one transaction; the request-time limit in
# panelalpha-php.ini is lower on purpose.
php -d memory_limit=512M panelalpha-install.php
