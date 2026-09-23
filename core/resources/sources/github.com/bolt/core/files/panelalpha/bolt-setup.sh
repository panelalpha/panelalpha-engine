#!/bin/sh
# Runs inside the container, in the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy, and a crash-looping container would replay it on every boot.
set -e
cd /app

# Symfony's container compile and the fixtures are the two expensive steps and
# the stock CLI memory_limit is not always enough for either. Far below the
# service's own cgroup limit.
CONSOLE="php -d memory_limit=512M bin/console"

# ---------------------------------------------------------------------------
# 1. The generated files Composer's plugins write, which the deploy's Composer
#    run was not allowed to produce.
#
# The php manifest installs with `--no-plugins` -- arbitrary PHP out of a
# customer repository, running on the host daemon -- and drops the flag only
# when composer.lock pins nothing but installer plugins
# (PhpHostBuild::mayRunPlugins()). bolt/core commits no composer.lock at all,
# so `lockedPlugins(null)` is empty, the exemption cannot apply, and three
# things Bolt cannot run without are never written:
#
#   vendor/autoload_runtime.php             symfony/runtime. Both entry points
#                                           require it -- public/index.php on
#                                           its second line and bin/console on
#                                           its first -- and without it every
#                                           request is `LogicException: Symfony
#                                           Runtime is missing`.
#   vendor/composer/../PackageVersions      composer/package-versions-deprecated,
#                                           read by Bolt\Version.
#   ComposerPackages\{Packages,Types}       drupol/composer-packages, read by
#                                           Bolt\Extension\ExtensionRegistry
#                                           inside a container compiler pass.
#
# `dump-autoload` re-fires POST_AUTOLOAD_DUMP, which is the event all three
# subscribe to, and writes no new code of its own. Here rather than in the
# build because the plugins are the account's to run, in the account's own
# container -- and because an app config's `commands:` never reach the build
# stage: AppConfig::readManifest() keeps `commands` out of the manifest, and
# PlatformValues only walks the manifest's.
#
# --no-scripts still: composer.json's `post-install-cmd` is Bolt's own
# CorePostInstallScript, and what it does (extensions:configure, cache:clear,
# assets:install) is done below, in the right order and without a nested
# Composer process.
composer dump-autoload --no-dev --optimize --no-interaction --no-scripts

# ---------------------------------------------------------------------------
# 2. Extension wiring, for what `--no-dev` actually installed.
#
# hooks/prepare.sh deleted the copies this repository commits: they point into
# vendor/ directories that only exist with dev dependencies, and the router
# throws FileLocatorFileNotFoundException on the missing path rather than
# skipping it. This is the command that generated them upstream, so it is the
# command that puts back whichever of them belong here.
$CONSOLE extensions:configure --with-config -n

# ---------------------------------------------------------------------------
# 3. The database, in the order the repository's own `make db-update` uses.
#
# Bolt ships no migrations on this branch -- migrations/ holds only .gitkeep,
# and `bolt:setup` generates the first one with `migrations:diff
# --from-empty-schema` -- so the schema comes from the entity mapping and the
# migration metadata is stamped, which is what stops a future Bolt migration
# from trying to create tables that are already there. The same command serves
# the upgrade stage: it diffs whatever is already there, so a replay says
# `Nothing to update`. Without `--complete`, which the repository's own
# `make db-update` still passes -- on doctrine/orm 3 it is a deprecated no-op
# and prints a warning into every boot's log.
$CONSOLE doctrine:schema:update --force -n
$CONSOLE doctrine:migrations:sync-metadata-storage -n -q
$CONSOLE doctrine:migrations:version --add --all -n -q || true

# Bundle assets (API Platform's Swagger UI, the translation web UI). Encore's
# own output is already in public/assets: the engine compiled it on the host,
# because package.json declares a `build` script and HostCompile::runForPhp
# runs the project's own package manager when it does.
$CONSOLE assets:install public -n

# ---------------------------------------------------------------------------
# 4. First deploy only: content, and one administrator whose password is not
#    in a public repository.
#
# `bolt:list-users` prints a Symfony table whose data rows start with the id,
# so counting lines that begin with a number counts users. An empty install is
# zero; anything else means this has already run and the account's own users
# are not to be touched.
users=$($CONSOLE bolt:list-users --max-results=5 2>/dev/null | grep -cE '^[[:space:]]+[0-9]+[[:space:]]' || true)
if [ "${users:-1}" -eq 0 ]; then
    # Bolt's demo content, and not decoration: HomepageController resolves
    # `homepage: homepage` to a record and TwigAwareController::renderSingle()
    # throws NotFoundHttpException when there is none, so a Bolt with an empty
    # database answers 404 on `/` -- up, and serving nothing.
    #
    # `--group=without-images` drops ImageFetchFixtures, which downloads
    # sample photographs at deploy time.
    #
    # Without `--append`, deliberately, and this is the one place upstream's
    # own `bolt:setup` cannot be copied: TaxonomyFixtures::load() returns
    # early when it sees `--append` in $_SERVER['argv'] and registers no
    # references, and ContentFixtures then dies in
    # BaseFixture::getRandomTaxonomies() on
    # `array_keys(): Argument #1 must be of type array, null given`. So the
    # fixtures run in their normal mode -- which creates six demo users with
    # the passwords printed in src/DataFixtures/UserFixtures.php (`admin%1`,
    # `jane%1`, `john%1` and three random ones) -- and every one of them has
    # its password replaced immediately below, before Apache binds.
    $CONSOLE doctrine:fixtures:load --group=without-images -n

    password=$(cat .panelalpha-admin-password)

    # The usernames come out of the fixture file in this checkout rather than a
    # list written here, so a Bolt that adds a demo user does not quietly add a
    # known password with it.
    for user in $(grep -oE "'username' => '[a-z0-9_]+'" src/DataFixtures/UserFixtures.php \
                  | sed -E "s/.*'([a-z0-9_]+)'\$/\1/"); do
        if [ "${user}" = 'admin' ]; then
            # bolt:reset-password takes no password argument -- it asks a
            # hidden Question -- so the generated one goes in on stdin. Under
            # -n the same question would return its own random default, which
            # is what every other account gets.
            printf '%s\n' "${password}" | $CONSOLE bolt:reset-password admin > /dev/null
        else
            $CONSOLE bolt:reset-password "${user}" -n > /dev/null 2>&1 || true
        fi
    done
fi

# ---------------------------------------------------------------------------
# 5. Compile the container and warm the cache while nothing is being served, so
#    the first visitor does not pay for it -- and so a failure here is a failed
#    deploy rather than a 500 for whoever arrives first.
$CONSOLE cache:clear -n
