#!/bin/bash
# Account shell, after the clone and before the build. Nothing here can know
# the database credentials -- the engine provisions those while it writes the
# compose file, after this hook -- so everything that needs them is in
# panelalpha-install.php instead.
set -e
cd ~/project

# 1. The first administrator's password, generated per account and never a
#    default. OpenEMR seeds no user, and until one exists index.php sends
#    every visitor to setup.php, an unauthenticated wizard on which the first
#    stranger to arrive becomes the superuser of an electronic health record.
#    The install therefore runs from the install stage, and it needs a
#    password that exists before it does.
#
#    Upstream's own automation ships `iuserpass = 'pass'`
#    (contrib/util/installScripts/InstallerAuto.php and
#    docker/release/auto_configure.php both), which is exactly the default
#    this file exists to avoid.
#
#    Alphanumeric on purpose. It is passed through
#    `escapeSql()` into an INSERT and through password_hash(), both of which
#    take anything -- but InstallerAuto.php, the upstream entry point an
#    operator is most likely to reach for next, parses its arguments with
#    `explode("=", $argv[$i])` and would silently truncate a password that
#    contains '='. A password that works from one entry point and not the
#    other is worse than a slightly smaller alphabet.
#
#    The umask is inside a subshell on purpose -- it has to cover the
#    redirection that creates the file, and it must not leak into the rest of
#    this script, where a 077 default would leave directories the engine
#    (www-data) cannot scan when it walks the tree for the document root.
if [ ! -f .panelalpha-admin-password ]; then
    ( umask 077; openssl rand -base64 24 | tr -d '/+=' | cut -c1-24 > .panelalpha-admin-password )
    chmod 600 .panelalpha-admin-password
fi

# 2. Directories OpenEMR writes into.
#
#    `sites/default/documents` is the only one setup.php's own permission
#    check insists on (setup.php builds $writableDirList from it), and it is
#    where every uploaded patient document, generated PDF and EDI batch ends
#    up afterwards. It is committed, so this is a no-op on a clean clone and
#    insurance on a restored one.
#
#    755, not 777: the container runs as this same account uid (the generated
#    compose file says `user: "<uid>:<gid>"`), so Apache is the owner.
mkdir -p sites/default/documents
chmod 755 sites sites/default sites/default/documents

# 3. `.htaccess.example` is deliberately NOT installed.
#
#    It is the opt-in switch for OpenEMR's experimental front controller, and
#    its own header says the rest of the tree's .htaccess files have to be
#    deleted with it: "EXAMPLE ONLY -- if you move this file from
#    `.htaccess.example` to `.htaccess` AND delete the other `.htaccess` files
#    throughout, you'll use the front controller. This is still an
#    experimental/opt-in process." Half of that migration is worse than
#    neither half. files/.htaccess is this recipe's own file and carries only
#    access rules; it is copied in by the engine, not by this hook.
