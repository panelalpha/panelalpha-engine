#!/bin/bash
# Account shell, after the clone and before detection and the build. Nothing
# here can know the database credentials -- the engine provisions those while
# it writes the compose file, after this hook -- so everything that needs them
# is in files/panelalpha/shopware-setup.sh instead.
set -e
cd ~/project

# ---------------------------------------------------------------------------
# 1. compose.yaml, which is a developer workstation and not a deployment.
#
# This is the file that produced the `serving-error_page` verdict: it is the
# only compose file in the tree, the compose-usable probe claims it at
# priority 980 against php's 930, and what it describes is the Shopware core
# team's laptop -- `ghcr.io/shopware/docker-dev:php8.4-node24-caddy` with the
# checkout bind-mounted at /var/www/html, a MariaDB whose root password is
# `root`, an Adminer on 9080, a Mailpit, a Valkey and an OpenSearch. Nothing
# in it installs Shopware, so Caddy served public/index.php against an empty
# vendor/ and every request was an error page.
#
# Moving it is not only about which strategy wins -- this recipe pins `php`
# through panelalpha.yaml, which PlatformSelector::fromSource() resolves ahead
# of the detection walk either way. It is about engine defect #166: a compose
# file left in the project root has its services mined as runtime sidecars
# even when the app itself is served another way, and the sidecars here are a
# root/root database and a public database administration UI.
#
# Kept rather than deleted, under .panelalpha/, so it is still there for
# whoever wants to read what upstream's dev environment looked like.
#
# The list is upstream's own filenames and nothing else. `docker-compose.*.yml`
# is the glob #166 suggests and it is WRONG here, because by the time this hook
# runs the engine has already written overrides/docker-compose.override.yml
# into the project root under exactly that name -- the order is clone, copy
# `files/` and `overrides/`, run this hook, then detect. A glob that catches it
# moves this recipe's own override out of the way, and the symptom is a deploy
# that looks fine and quietly has no PHP_INI_SCAN_DIR, no healthcheck and no
# `ready` gate. (Measured: it lands in .panelalpha/upstream-docker-compose.override.yml
# and the generated compose has none of it.) This repository ships no
# `docker-compose.*` file of its own, so naming upstream's four spellings costs
# nothing and cannot collide.
mkdir -p .panelalpha
for f in compose.yaml compose.yml compose.override.yaml compose.override.yml; do
    [ -e "$f" ] || continue
    mv -f "$f" ".panelalpha/upstream-$f"
    echo "[shopware] moved $f out of the project root (engine defect #166)"
done

# ---------------------------------------------------------------------------
# 2. The first administrator's password, generated per account, never a default.
#
# This one is not optional. `bin/console system:install --basic-setup` is the
# documented one-shot install, and SystemInstallCommand.php:130-137 spells the
# credential it creates literally:
#
#     'command' => 'user:create', 'username' => 'admin',
#     '--admin' => true, '--password' => 'shopware'
#
# admin / shopware, published in the repository, on a public HTTPS domain.
# files/panelalpha/shopware-setup.sh therefore never passes --basic-setup; it
# runs user:create itself with the password generated here.
#
# The umask is inside a subshell on purpose -- it has to cover the redirection
# that creates the file, so the password is never briefly world-readable, and
# it must not survive into the rest of this script, where a 077 default would
# leave directories the engine (www-data) cannot scan when it walks the tree.
#
# ~/project, not $HOME: account home directories are root-owned 755. It is
# outside the document root, which is public/.
if [ ! -f .panelalpha-admin-password ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > .panelalpha-admin-password )
    chmod 600 .panelalpha-admin-password
fi

# ---------------------------------------------------------------------------
# 3. .env, which has to exist before the container is created.
#
# Two separate reasons, both load-bearing:
#
#   public/index.php:13 and bin/console:14 set
#   APP_RUNTIME_OPTIONS.disable_dotenv when none of .env, .env.dist and
#   .env.local.php is present. The repository ships none of them (/.env is in
#   .gitignore), so without this file Symfony's Dotenv never runs and
#   .env.local -- where shopware-setup.sh puts DATABASE_URL, because that is
#   the only value it cannot know until the engine has provisioned the
#   database -- is never read.
#
#   The generated compose file carries `env_file: ['.env']` unconditionally
#   (FrameworkService::runtimeEnvironment()), and compose reads it when the
#   container is created, which is after this hook and before any stage
#   command.
#
# So: everything that must be fixed for the life of the account goes here, and
# nothing that the install stage needs to decide. DATABASE_URL is deliberately
# absent -- a line here becomes a real process environment variable, and
# Dotenv::populate() never overwrites one of those, so an empty or stale
# DATABASE_URL in this file would silently beat the correct one in .env.local.
if [ ! -f .env ]; then
    cat > .env <<EOF
# Written by the PanelAlpha shopware recipe. Values that are true for the life
# of this account. The database URL is in .env.local, which the install stage
# writes once the engine has provisioned the database.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
INSTANCE_ID=$(openssl rand -hex 16)
LOCK_DSN=flock
MAILER_DSN=null://null
SHOPWARE_ES_ENABLED=0
SHOPWARE_ES_INDEXING_ENABLED=0
BLUE_GREEN_DEPLOYMENT=0
EOF
    chmod 600 .env
fi

# ---------------------------------------------------------------------------
# 4. The directories .gitignore keeps out of the clone and Shopware writes to.
#
# /public/* and /var/* and files/* are all ignored upstream, so the checkout
# arrives with public/ holding index.php and .htaccess.dist and nothing else.
# The container starts Apache from whatever is on disk and the engine walks
# this tree looking for the document root before either runs.
#
# 755, not 777: the container runs as this same account uid (the generated
# compose file says `user: "<uid>:<gid>"`), so Apache is the owner.
mkdir -p var/cache var/log \
         files/documents files/downloads \
         public/media public/thumbnail public/sitemap public/theme public/bundles \
         config/jwt custom/plugins custom/apps
chmod 755 var var/cache var/log files public config custom
