#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy, and a crash-looping container would replay it on every boot.
#
# It lives in panelalpha/ rather than at the top of the checkout because
# Thelia's document root is public/ -- nothing here is web-reachable to begin
# with.
set -e
cd /app

# Symfony's prod container compile, Propel's model build and the SQL import are
# the expensive steps, and the stock CLI memory_limit is not always enough for
# the first. Far below the service's own cgroup limit, which the compose
# override raises to 1g for the same reason.
PHP='php -d memory_limit=768M'
# Thelia's console is the `Thelia` executable at the top of the checkout, not
# bin/console: .gitignore lists `/bin/`, the Flex recipe that would write
# bin/console never ran (composer installs with --no-plugins, so symfony/flex
# is not activated), and a clone ships only bin/install and bin/test-prepare.
# A `bin/console cache:clear` here printed `Could not open input file`.
CONSOLE="${PHP} Thelia"

# Composer writes its cache and its auth file under $COMPOSER_HOME; the
# account's home is the bind-mounted checkout, and a dump-autoload that cannot
# write there prints a warning on every deploy. /tmp is the container's own.
COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"
export COMPOSER_HOME

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[thelia] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. The modules, where composer/installers would have put them.
#
# The whole of why is in panelalpha/thelia-place-modules.php: `--no-plugins`
# means composer/installers never read `extra.installer-paths`, so all sixteen
# thelia-module packages are at `vendor/thelia/<package>` and not at
# `vendor/thelia/modules/<Name>` -- which is the only place
# core/bootstrap.php's THELIA_MODULE_DIR points and the only place
# DatabaseSetup::registerAndApplyModules() looks. It links rather than moves,
# for reasons the file sets out: Composer drops a package it can no longer
# find, and everything reachable only through it.
#
# The templates (thelia/flexy, the two back-office ones, the email and PDF
# ones) are deliberately left where Composer put them. bin/install copies them
# into templates/<type>/<name> itself, through
# ComposerHelper::findInstalledPackagePathByTypeAndInstallerName(), which reads
# `install-path` out of installed.json -- correct wherever they landed.
${PHP} panelalpha/thelia-place-modules.php

# ---------------------------------------------------------------------------
# 2. vendor/autoload_runtime.php, which Composer was not allowed to write.
#
# symfony/runtime is a composer-plugin: it writes that file on
# POST_AUTOLOAD_DUMP, and it is required on the second line of
# public/index.php and the first of the `Thelia` console. Under `--no-plugins`
# it is installed and never activated, so neither entry point can boot --
# `LogicException: Symfony Runtime is missing` -- and that is the HTTP 500 on
# every path that this recipe exists to fix.
#
# `dump-autoload` re-fires that one event. Here rather than in the build
# because the plugins are the account's to run, in the account's own container
# -- and because an app config's `commands:` never reach the build stage:
# AppConfig::readManifest() keeps `commands` out of the manifest and
# PlatformValues only walks the manifest's.
#
# It runs twice, and the order is the whole point.
#
#   1. with plugins. This is the run that fires POST_AUTOLOAD_DUMP and writes
#      vendor/autoload_runtime.php. Its *map* is wrong, and not by a little:
#      composer/installers is active, so Composer believes thelia/flexy lives
#      at templates/frontOffice/flexy and the back-office templates at
#      templates/backOffice/<name> -- where nothing is yet, because the copy
#      is bin/install's job -- and drops them together with everything
#      reachable only through them. Measured: 130 psr-4 prefixes instead of
#      157, missing FlexyBundle, the whole Symfony UX set, twig/extra-* and
#      liip/imagine-bundle, which is exactly the
#      `Class "Liip\ImagineBundle\LiipImagineBundle" not found` the kernel
#      dies on while registering config/bundles.php.
#
#   2. without plugins, which is what the host build ran and what matches
#      where the files actually are: every package at vendor/<name>, all 157
#      prefixes, liip included. It rewrites vendor/composer/autoload_*.php and
#      leaves vendor/autoload_runtime.php -- not Composer's file -- alone.
#
# So the first run is for the plugin and the second for the map. Neither run
# writes any code of its own.
#
# --no-scripts on both: composer.json's post-install-cmd clears the cache and
# generates the JWT key pair through bin/console, which does not exist here,
# and bin/install does both itself in the right order.
composer dump-autoload --no-dev --optimize --no-interaction --no-scripts
composer dump-autoload --no-dev --optimize --no-interaction --no-scripts --no-plugins

# ---------------------------------------------------------------------------
# 3. The bundles Flex would have registered.
#
# config/bundles.php is generated by Flex, the copy in the repository has
# fallen three bundles behind the package set, and with `--no-plugins` nothing
# catches up. panelalpha/thelia-register-bundles.php has the detail; the short
# version is that without TwigTailwindExtraBundle every front-office page is
# `Unknown "tailwind_merge" filter`, and without the two SymfonyCasts bundles
# `tailwind:build` and `sass:build` do not exist, so the themes' stylesheets
# are never compiled.
#
# Before bin/install, because bin/install compiles the container that has to
# contain them.
${PHP} panelalpha/thelia-register-bundles.php

# ---------------------------------------------------------------------------
# 4. Install, or re-point an install that is already there.
#
# "Already there" is a fact about the database -- the `module` table, which
# DatabaseSetup creates from setup/thelia.sql -- and not about a marker file,
# which the next redeploy's re-clone would wipe along with the rest of
# ~/project. A redeploy re-clones the checkout but keeps the account's MySQL
# database, so this is the branch that matters most often.
if ${PHP} -r '
    try {
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            (string) getenv("DB_USERNAME"),
            (string) getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "[thelia] cannot reach the database: " . $e->getMessage() . "\n");
        exit(2);
    }
    exit($pdo->query("SHOW TABLES LIKE \"module\"")->fetchColumn() === false ? 1 : 0);
'; then
    echo "[thelia] already installed; re-pointing .env.local and clearing the cache"

    # The credentials are stable across redeploys -- the engine keeps the
    # database password in the account's encrypted details precisely so a
    # redeploy cannot invalidate a config file -- but the checkout is not:
    # GitRepository::cloneConfiguredRepository() clears ~/project before it
    # clones, so .env.local is gone on every redeploy and has to be written
    # again. Same block, same markers, same shape bin/install writes.
    ${PHP} -r '
        $file = "/app/.env.local";
        $block = sprintf(
            "\n###> thelia/database-configuration ###\nDATABASE_HOST=%s\nDATABASE_PORT=%s\nDATABASE_NAME=%s\nDATABASE_USER=%s\nDATABASE_PASSWORD=%s\n###< thelia/database-configuration ###\n",
            getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")
        );
        $existing = is_file($file) ? (string) file_get_contents($file) : "";
        $existing = (string) preg_replace("/\n?###> thelia\/database-configuration ###.*?###< thelia\/database-configuration ###\n?/s", "", $existing);
        $old = umask(077);
        file_put_contents($file, $existing . $block);
        umask($old);
        chmod($file, 0600);
    '

    # An APP_SECRET is not optional in prod, and hooks/prepare.sh only put one
    # in .env -- which a redeploy restores from the clone, so there is nothing
    # to carry over here. Nothing else in .env.local is Thelia's to keep.
    $CONSOLE cache:clear --no-interaction
    exit 0
fi

# A first install. The password was generated per account by hooks/prepare.sh
# before anything could serve a page.
if [ ! -r .panelalpha-admin-password ]; then
    echo "[thelia] .panelalpha-admin-password is missing; hooks/prepare.sh writes it" >&2
    exit 1
fi
password=$(cat .panelalpha-admin-password)
if [ -z "${password}" ]; then
    echo "[thelia] the generated administrator password is empty" >&2
    exit 1
fi

# A hook is told neither the account's address nor its domain, so an address
# that is stable and obviously a placeholder beats one that looks real. The
# password is the secret, not the address; bin/install also seeds it as the
# shop's notification recipient, which an administrator changes in the back
# office.
admin_email="${THELIA_ADMIN_EMAIL:-admin@example.com}"

# Thelia's own installer, with the credentials the engine provisioned passed as
# flags rather than left to the environment: the DATABASE_* keys were taken out
# of .env by hooks/prepare.sh, and flags are what bin/install documents.
#
# It does the whole install standalone -- permissions, CREATE DATABASE IF NOT
# EXISTS over the one the engine already made, setup/thelia.sql and
# setup/insert.sql, the form secret, .env.local, module registration, the four
# templates out of vendor/, the JWT key pair, module post-activation and the
# administrator -- and only then boots the kernel. There is no web installer to
# fall back on: public/install/bdd.php is an empty file on this branch.
#
# No --with-demo. The demo catalogue is upstream's showroom, not a shop anyone
# asked to be given, and its images are fetched at deploy time.
echo "[thelia] installing"
set +e
${PHP} bin/install \
    --database_host="${DB_HOST}" \
    --database_port="${DB_PORT:-3306}" \
    --database_name="${DB_DATABASE}" \
    --database_user="${DB_USERNAME}" \
    --database_password="${DB_PASSWORD}" \
    --frontoffice_theme=flexy \
    --backoffice_theme=default-twig \
    --pdf_theme=default \
    --email_theme=default \
    --with-admin \
    --admin_login=admin \
    --admin_first_name=Admin \
    --admin_last_name=Thelia \
    --admin_email="${admin_email}" \
    --admin_password="${password}"
install_status=$?
set -e

# ---------------------------------------------------------------------------
# 5. Put the autoload map back, a third time.
#
# bin/install's `template:set` ends in `Thelia\Command\SetTemplate`, which
# shells out to `vendor/bin/composer dump-autoload` -- with plugins, because it
# is Composer's default -- after adding the active theme's namespace to the
# root composer.json. So the last word on the autoloader belongs to a run with
# composer/installers active, and it drops the one template package that is
# *not* the active theme: thelia/backoffice-default-template, which
# composer/installers places at templates/backOffice/default, where nothing is,
# because only the selected themes are copied out of vendor/.
#
# config/bundles.php registers its bundle all the same -- the file is committed
# and lists all three template bundles -- so every request answered
# `Class "BackOfficeDefaultBundle\BackOfficeDefaultBundle" not found`, with the
# shop fully installed behind it. Measured: 154 psr-4 prefixes instead of 157,
# the three missing ones all that package's.
composer dump-autoload --no-dev --optimize --no-interaction --no-scripts --no-plugins

# bin/install writes the credentials into .env.local in the clear. It is
# outside the document root (public/ is), and the generated vhost denies
# dotfiles, but the file is also the account's MySQL password sitting at mode
# 644 in a bind-mounted home directory.
chmod 600 .env.local

# ---------------------------------------------------------------------------
# 6. What bin/install's exit code means here.
#
# It exits 1 if *any* of its staged commands failed, and the last three --
# importmap:install, tailwind:build, sass:build -- fetch the front office's
# JavaScript from jsdelivr and build its stylesheets. A jsdelivr timeout is a
# bad minute on someone else's CDN, not a failed install: the schema, the
# modules, the templates, the JWT keys and the administrator are all already
# there, and failing the stage over it would put the container in a crash loop
# and roll the whole account back. Measured on the first end-to-end run:
# `Idle timeout reached for "https://cdn.jsdelivr.net/npm/@formatjs/
# icu-skeleton-parser@1.8.16/+esm"`, with everything else done.
#
# So the exit code is checked against the database instead: an `admin` row is
# what says the install got to the end. If it did, the three asset commands are
# retried once each and a failure is reported rather than fatal; if it did not,
# this is a real failure and the stage fails with it.
if [ "${install_status}" -ne 0 ]; then
    if ! ${PHP} -r '
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            (string) getenv("DB_USERNAME"),
            (string) getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        exit((int) $pdo->query("SELECT COUNT(*) FROM admin")->fetchColumn() > 0 ? 0 : 1);
    '; then
        echo "[thelia] bin/install failed and the shop has no administrator; see above" >&2
        exit "${install_status}"
    fi

    echo "[thelia] bin/install reported an error; the shop is installed, retrying the asset builds" >&2
    for asset_command in importmap:install tailwind:build sass:build; do
        $CONSOLE "${asset_command}" --no-interaction \
            || echo "[thelia] ${asset_command} did not complete; the shop serves, its front-office assets may not be built" >&2
    done
fi

# Compile the container and warm the cache while nothing is being served, so
# the first visitor does not pay for it. Optional: bin/install already cleared
# and rebuilt it through template:set, and a warmup that fails is not a reason
# to fail a deploy that has a working shop behind it.
# A cache *clear*, not a warmup: bin/install's own cache:clear calls happened
# inside a process that had just built the container, and the last thing this
# stage wants is a compiled container from halfway through the install. clear
# warms as part of its job.
$CONSOLE cache:clear --no-interaction

echo "[thelia] installed; the administrator is 'admin' (${admin_email}), password in .panelalpha-admin-password"
