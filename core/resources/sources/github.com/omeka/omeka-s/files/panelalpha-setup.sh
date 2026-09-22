#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy.
#
# The name is load-bearing. Omeka S serves from the repository root, so this
# file sits inside the document root -- and the generated vhost denies
# `^(?:docker-compose\.ya?ml|panelalpha[-.])` outright, which is why it is
# `panelalpha-setup.sh` at the top rather than `panelalpha/setup.sh` one level
# down. A directory of that name would be served.
set -e
cd /app

# ---------------------------------------------------------------------------
# 1. config/database.ini, from the database the engine provisioned.
#
# `database: mysql` in panelalpha.yaml is what asks for it: AppDatabase creates
# a database and user on the account's own MySQL server -- visible in the
# panel, openable in phpMyAdmin, included in the account's backup -- and the
# generated compose file passes the credentials as DB_*. This is the only place
# they can be written: hooks/prepare.sh runs before the database exists.
#
# Rewritten whenever it does not match, not only when it is missing. The
# credentials are stable across redeploys (AppDatabase keeps the password in
# the account's encrypted details precisely so a redeploy cannot invalidate a
# config file), but a restored backup or a hand-edited file that points at
# nothing is a 500 on every request, and the values the container was handed
# are by definition the ones that work.
#
# 0600 because it holds a password. The container is the account uid -- the
# compose file says `user: "<uid>:<gid>"` -- so Apache is the owner and can
# read it. .htaccess denies `.ini` to the web as well; both, because either one
# alone is a single edit away from publishing this file.
ini_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

write_database_ini() {
    ( umask 077; cat > config/database.ini <<EOF
; Written by PanelAlpha on every deploy from the credentials of the database
; provisioned for this account. Edit config/local.config.php for anything else;
; this file is regenerated.
user     = "$(ini_escape "${DB_USERNAME}")"
password = "$(ini_escape "${DB_PASSWORD}")"
dbname   = "$(ini_escape "${DB_DATABASE}")"
host     = "$(ini_escape "${DB_HOST}")"
port     = "$(ini_escape "${DB_PORT:-3306}")"
EOF
    )
    chmod 600 config/database.ini
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[omeka-s] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

if ! grep -q "^dbname *= *\"${DB_DATABASE}\"$" config/database.ini 2>/dev/null \
   || ! grep -q "^host *= *\"${DB_HOST}\"$" config/database.ini 2>/dev/null; then
    write_database_ini
    echo "[omeka-s] wrote config/database.ini for ${DB_DATABASE}@${DB_HOST}"
fi

# ---------------------------------------------------------------------------
# 2. The default theme, which Composer put in the wrong place.
#
# `omeka-s-themes/default` is `"type": "omeka-s-theme"` and the installer that
# knows what that means is omeka/composer-addon-installer -- a composer-plugin
# the repository ships in-tree (application/data/composer-addon-installer, a
# `path` repository) whose getInstallPath() returns `themes/<name>`. The php
# manifest installs with `--no-plugins`, because a plugin is arbitrary PHP out
# of a customer repository and the install runs on the host daemon, and
# PhpHostBuild::mayRunPlugins() lifts that only for the five installer plugins
# it names. So Composer used its own LibraryInstaller and the theme is in
# vendor/.
#
# Omeka reads themes only from OMEKA_PATH/themes (Site\ThemeManager scans that
# directory for config/theme.ini), so without this the account has no theme,
# and creating a public site -- the point of Omeka S -- fails.
#
# Copied rather than symlinked: the theme's own asset paths are resolved
# against its real location, and a symlink into vendor/ would break the moment
# a redeploy re-resolved dependencies.
if [ ! -f themes/default/config/theme.ini ] && [ -f vendor/omeka-s-themes/default/config/theme.ini ]; then
    mkdir -p themes
    cp -a vendor/omeka-s-themes/default themes/default
    echo "[omeka-s] copied the default theme out of vendor/ into themes/"
fi

# ---------------------------------------------------------------------------
# 3. Install, or migrate.
#
# See files/panelalpha-install.php: on a database with no `module` table it
# runs Omeka's own Installer; on one that has it, its migrations. 512M because
# the install builds the schema and then parses four RDF vocabularies through
# EasyRdf, and the stock CLI limit is not always enough for the second.
php -d memory_limit=512M panelalpha-install.php
