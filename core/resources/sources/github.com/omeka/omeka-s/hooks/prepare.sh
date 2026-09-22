#!/bin/bash
# Account shell, after the clone and before the build. Nothing here can know
# the database credentials -- the engine provisions those while it writes the
# compose file, after this hook -- so everything that needs them is in
# panelalpha-setup.sh instead.
set -e
cd ~/project

# 1. The front controller's rewrite rules, and the only thing that keeps
#    config/ out of the web.
#
#    Omeka S serves from its own root, and .gitignore lists `/.htaccess`: the
#    repository ships `.htaccess.dist` and expects the installer to copy it.
#    Without it Apache serves the checkout as a directory of files -- every
#    route but `/` is a 404, and `GET /config/database.ini` returns the
#    account's MySQL password in plain text. The generated vhost denies
#    dotfiles and `panelalpha*`, which is why the setup script and the password
#    file are safe on their own, but it says nothing about `.ini`.
#
#    Copied rather than symlinked so an operator can edit it, and never
#    overwritten: a site that has been tuned keeps its rules.
if [ ! -f .htaccess ] && [ -f .htaccess.dist ]; then
    cp .htaccess.dist .htaccess
    echo "[omeka-s] installed .htaccess from .htaccess.dist"
fi

# 2. The first administrator's password, generated per account and never a
#    default. Omeka S creates no user of its own: whoever reaches /install
#    first becomes the global admin, so the install runs from the install
#    stage and needs a password that exists before it does.
#
#    The umask is inside a subshell on purpose -- it has to cover the
#    redirection that creates the file, and it must not leak into the rest of
#    this script, where a 077 default would leave directories the engine
#    (www-data) cannot scan when it walks the tree for the document root.
if [ ! -f .panelalpha-admin-password ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > .panelalpha-admin-password )
    chmod 600 .panelalpha-admin-password
fi

# 3. Directories Omeka writes into.
#
#    `files/` is checked by the installer itself -- CheckDirPermissionsTask
#    fails the install outright when it is not writable -- and holds every
#    uploaded original and derivative afterwards. `themes/` and `modules/` are
#    in .gitignore with only a README committed, and the setup script copies
#    the default theme into the first. `logs/` ships only .dist files; Omeka's
#    logger is off by default and errors go to stderr, so this is for an
#    operator who turns it on in config/local.config.php.
#
#    755, not 777: the container runs as this same account uid (the generated
#    compose file says `user: "<uid>:<gid>"`), so Apache is the owner.
mkdir -p files logs themes modules
chmod 755 files logs themes modules
