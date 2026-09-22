#!/bin/bash
# Account shell, after the clone and before detection.
#
# Three things that have to be true before anything is built or served, and
# that nothing later in the deploy can do:
#   1. the super-admin password, which must outlive the checkout;
#   2. the runtime directories .gitignore keeps out of the repository;
#   3. display_errors, which upstream's own www/.htaccess turns back on.
set -e
cd ~/project

# ---------------------------------------------------------------- password --
#
# Every deploy re-clones over ~/project while the account's MySQL database --
# and the md5 hash in zt_user -- stays exactly where it was, so a password
# generated beside the code would be a new password on every redeploy, matching
# nothing. ~/.panelalpha is where it goes, and a directory rather than the home
# itself because account homes are root-owned and 0755: an account cannot
# create a file directly in its own home. #173 also writes .env.default into
# the checkout 0644 and readable by every other tenant, which is the other
# reason nothing secret belongs in ~/project.
STORE_DIR="$HOME/.panelalpha"
mkdir -p "$STORE_DIR"
chmod 700 "$STORE_DIR"
PW_STORE="$STORE_DIR/zentao-admin-password"

if [ ! -f "$PW_STORE" ]; then
    # installModel::grantPriv() rejects anything under 6 characters, anything
    # in $config->safe->weak, and anything computePasswordStrength() scores
    # below 1 -- which wants mixed classes rather than length alone. So this is
    # built to pass that on purpose: 20 base62 characters from openssl, with a
    # digit and an upper-case letter appended so the class mix never depends on
    # luck. The base64 punctuation is dropped because ZenTao's own login form
    # has to survive a human retyping it.
    (
        umask 077
        {
            openssl rand -base64 30 | tr -d '/+=\n' | cut -c1-20
            printf 'a7Z\n'
        } | tr -d '\n' > "$PW_STORE"
        printf '\n' >> "$PW_STORE"
    )
    chmod 600 "$PW_STORE"
fi

# The container cannot see ~/.panelalpha -- only ~/project is bind-mounted at
# /app -- so the value is copied in as a dotfile at the repository root. That
# is outside the document root (www/) twice over: the vhost denies every path
# component starting with a dot, and nothing under /app is served at all.
( umask 077; cp "$PW_STORE" .panelalpha-admin-password )
chmod 600 .panelalpha-admin-password

# ------------------------------------------------------------- directories --
#
# .gitignore keeps tmp/* and www/data/ out of the repository, so a clone has
# neither and ZenTao cannot write its cache, its compiled templates, its
# session files or a single attachment. The container runs as this same account
# uid (the generated compose file says `user: "<uid>:<gid>"`), so the account is
# the owner either way and 0755 is enough -- upstream's Makefile chmods these
# 0777 for a tarball that will be unpacked by an unknown user, which is not
# this situation.
mkdir -p tmp/model tmp/cache tmp/log tmp/session tmp/duckdb
mkdir -p www/data/upload
chmod -R 755 tmp www/data

# ---------------------------------------------------------- display_errors --
#
# #185: the shared PHP base image loads no php.ini, so display_errors is on
# platform-wide, and upstream's www/.htaccess then asserts it again --
# `php_value display_errors 1` under <IfModule php_module>, which is the mod_php
# 8 module name, and the base image is mod_php (panelalpha-serve.sh execs
# apache2-foreground). A single notice would otherwise be rendered into the page
# for every visitor, and on the CLI side it makes headers_sent() true and breaks
# install scripts.
#
# Appended rather than replacing the file, because the rest of www/.htaccess is
# ZenTao's mod_rewrite front-controller routing and its 100M upload limits, and
# a later directive of the same name wins. Guarded so a redeploy does not stack
# copies -- though the clone that precedes this hook has already removed any.
if ! grep -q 'PanelAlpha: display_errors' www/.htaccess 2>/dev/null; then
    cat >> www/.htaccess <<'HTACCESS'

# PanelAlpha: display_errors. Upstream turns this on a few lines above; the
# shared base image has no php.ini to turn it off centrally, so it is turned
# off here. Diagnostics belong in the container's error log, which the vhost
# already points at stderr, and not in the response body.
<IfModule php_module>
php_flag display_errors Off
php_flag log_errors On
</IfModule>

# PanelAlpha: developer and operator tools that ship inside the document root.
#
# www/ is the document root, and upstream puts more than the front controller
# in it. Each of these answered 200 to an unauthenticated request on a real
# deploy, and none of them is reachable through index.php, so denying them
# costs the application nothing:
#
#   cache.php       an APC/OPcache/Redis/Memcached console. Not only a status
#                   page: `?action=redis_clear` calls flushDb() and
#                   `?action=memcache_clear` calls flush(), from $_GET, with no
#                   authentication at all.
#   checktable.php  includes ../config/config.php -- so it runs with the
#                   account's database credentials -- and offers table checking
#                   and repair to whoever asks.
#   dev.php         "Zentao Dev Tools": a SQL profile browser.
#   coverage.php    test-coverage reports; pulls in test/lib/coverage.php.
#   webcoverage.php the same, over HTTP.
#   init.php        a bare framework bootstrap that builds a commonModel named
#                   "tester". A test harness entry, not a page.
#   worker.php      the RoadRunner/FrankenPHP worker entry. This deployment is
#                   mod_php; it has no business answering a request.
#   cron.php        the cron entry, meant for the CLI.
#   *.tmp           install.php.tmp and upgrade.php.tmp. Apache has no handler
#                   for .tmp, so it serves them as plain text -- and they are
#                   the file the Makefile renames into a live web installer
#                   (Makefile:85-86). Denied rather than deleted, so the
#                   checkout stays exactly as upstream ships it.
#
# Anchored on the whole name so a page the account adds later is unaffected.
# index.php, api.php, imgproxy.php and the asset directories are untouched.
<FilesMatch "^(?:cache|checktable|dev|coverage|webcoverage|init|worker|cron)\.php$">
    Require all denied
</FilesMatch>
<FilesMatch "\.tmp$">
    Require all denied
</FilesMatch>
HTACCESS
fi
