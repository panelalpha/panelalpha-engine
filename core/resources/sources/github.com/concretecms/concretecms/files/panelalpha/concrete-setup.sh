#!/bin/sh
# Runs inside the container, in the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy, and a crash-looping container would replay it on every boot.
set -e
cd /app

say() { echo "[concrete] $*" >&2; }

# Concrete's console asks for more than the stock CLI memory_limit in two
# places: the install, which builds ~250 tables and imports a starting point in
# one process, and c5:update, which replays Doctrine migrations. Well below the
# service's cgroup ceiling (768m, set in the compose override).
C5="php -d memory_limit=512M concrete/bin/concrete"

# ---------------------------------------------------------------------------
# 1. Is the autoloader the one hooks/prepare.sh arranged for?
#
# A deploy where the merge in hooks/prepare.sh did not happen -- a fork that
# moved concrete/composer.json, a host without python3 -- installs 158 packages
# and autoloads nine classes, and every request answers
# `Class "Concrete\Core\Foundation\ClassAutoloader" not found`. That is the
# defect this recipe exists to fix and it is worth one cheap assertion rather
# than a fatal in a browser.
#
# The fallback is the same merge done by the plugin that was not allowed to run
# on the host -- here it runs in the account's own container, as the account's
# own uid, which is the boundary PhpHostBuild::SAFE_INSTALL_FLAGS is about.
if ! php -r 'require "concrete/vendor/autoload.php"; exit(class_exists("Concrete\\Core\\Foundation\\ClassAutoloader") ? 0 : 1);' 2>/dev/null; then
    say "WARNING: Concrete\\Core is not on the autoloader; re-dumping with Composer plugins enabled"
    composer dump-autoload --no-dev --optimize --no-interaction --no-scripts 2>&1 | tail -3 >&2
fi

# ---------------------------------------------------------------------------
# 2. Wait for the database.
#
# The install is the first thing that touches it, and its "Database should be
# empty" precondition cannot tell a MySQL that is still starting from one that
# refused the credentials -- both come back as a failed precondition and a
# failed deploy.
i=0
while [ "$i" -lt 60 ]; do
    if php -r '
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
            getenv("DB_HOST") ?: "127.0.0.1",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_DATABASE"));
        try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }
        exit(0);
    ' 2>/dev/null; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
[ "$i" -lt 60 ] || say "WARNING: database still unreachable after 120s; continuing anyway"

# ---------------------------------------------------------------------------
# 3. Installed, or not?
#
# Concrete has no `install/check`. The honest question is whether its schema is
# in the database, so that is what is asked -- not whether a config file exists,
# which would call a half-finished first deploy "installed" and then never
# finish it. `Pages` is a core table created by the install itself.
installed=no
if php -r '
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
        getenv("DB_HOST") ?: "127.0.0.1",
        getenv("DB_PORT") ?: "3306",
        getenv("DB_DATABASE"));
    try {
        $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->query("SELECT 1 FROM Pages LIMIT 1");
    } catch (Throwable $e) { exit(1); }
    exit(0);
' 2>/dev/null; then
    installed=yes
fi

# The site URL Concrete stores. It keeps this verbatim in
# generated_overrides/site.php and builds every absolute link and every
# redirect from it, so it has to be the account's real public domain and it has
# to follow that domain when it changes -- there is no `$VAR` indirection here
# the way Craft has one. The install sets it; step 5 re-states it on every
# deploy after. c5:install's own precondition insists on the trailing slash.
site_url=""
if [ -n "${APP_URL:-}" ]; then
    site_url="${APP_URL%/}/"
fi

if [ "$installed" = "no" ]; then
    # ---------------------------------------------------------------------
    # 4. The install, run here so that it is never run by a visitor.
    #
    # application/config/database.php first, because it decides which of two
    # applications `concrete/bin/concrete` boots. With no connection file the
    # console runs in installer mode, which is the only mode c5:install works
    # in; with one, it boots the whole CMS before parsing the command line and
    # dies on `Table 'x.Packages' doesn't exist` against the empty database it
    # was about to create (Concrete\Core\Package\PackageList::get(), reached
    # from Application.php:235). A file left behind by a previous deploy that
    # failed part-way through is exactly that case, and the bind mount means it
    # does outlive the checkout.
    #
    # This is the security half of the recipe. An uninstalled Concrete
    # redirects *every* request to /install, and that wizard creates the
    # administrator: whoever reaches it first owns the site. Running
    # c5:install from the install stage, before Apache binds, with a password
    # generated per account into ~/.panelalpha/concrete/concrete.env, means
    # the window never opens.
    #
    # --session-handler=database rather than Concrete's default `file`.
    # PHP's own session directory in this image is /tmp, which is inside the
    # container's writable layer: `docker compose up` on a redeploy replaces
    # the container and signs out everyone who was logged in, including in the
    # middle of editing a page. Concrete's database handler is a supported
    # installer option and puts them somewhere that survives.
    #
    # The administrator's username is not a choice -- Concrete's installer
    # always creates `admin` and the CLI has no flag for it.
    if [ -z "$site_url" ]; then
        # c5:install validates the canonical URL and an empty one fails the
        # deploy. A placeholder installs; step 5 corrects it on the first
        # deploy after the account has a domain.
        site_url="http://localhost/"
        say "WARNING: no APP_URL; installing with a placeholder canonical URL"
    fi

    rm -f application/config/database.php

    say "installing Concrete (starting point: ${PA_CONCRETE_STARTING_POINT:-atomik_blank})"
    $C5 c5:install \
        --no-interaction \
        --db-server="${DB_HOST}" \
        --db-database="${DB_DATABASE}" \
        --db-username="${DB_USERNAME}" \
        --db-password="${DB_PASSWORD}" \
        --admin-email="${PA_CONCRETE_ADMIN_EMAIL:-admin@example.com}" \
        --admin-password="${PA_CONCRETE_ADMIN_PASSWORD:?no administrator password in the environment}" \
        --site="${PA_CONCRETE_SITE_NAME:-Concrete CMS}" \
        --canonical-url="$site_url" \
        --starting-point="${PA_CONCRETE_STARTING_POINT:-atomik_blank}" \
        --session-handler=database \
        --language=en_US
else
    # ---------------------------------------------------------------------
    # The connection, refreshed from the environment rather than left as the
    # copy the installer froze. `database: mysql` is what makes
    # AppDatabase::provision() hand this container DB_HOST / DB_DATABASE /
    # DB_USERNAME / DB_PASSWORD, and that provisioning is idempotent -- the
    # same account gets the same database and the same password on every
    # deploy -- but "idempotent" is a property of the engine, not a promise to
    # the app, and a rotated password would otherwise leave Concrete holding a
    # connection string nothing answers. Written before the migrations so they
    # run against it. 0600: it is the account's MySQL password in a file, in a
    # bind mount under ~/.panelalpha/concrete/config.
    if [ -n "${DB_DATABASE:-}" ]; then
        umask 077
        cat > application/config/database.php <<EOF
<?php

/**
 * Rewritten by PanelAlpha on every deploy from the container's own
 * environment. The database and its user are provisioned on the account's own
 * MySQL server (App\System\Project\Dind\AppDatabase) and are visible in the
 * panel and in phpMyAdmin. Editing this file by hand will not survive.
 */

return [
    'default-connection' => 'concrete',
    'connections' => [
        'concrete' => [
            'driver' => 'concrete_pdo_mysql',
            'server' => '${DB_HOST}',
            'database' => '${DB_DATABASE}',
            'username' => '${DB_USERNAME}',
            'password' => '${DB_PASSWORD}',
            'character_set' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
];
EOF
        umask 022
    fi

    # Migrations, on every deploy that changed the code. c5:update is
    # Concrete's "make the database match the core": it runs the Doctrine
    # migrations between the installed version and the checkout's. On a
    # redeploy of the same commit it finds nothing to do and says so.
    say "existing installation found; running migrations"
    $C5 c5:update --no-interaction
fi

# ---------------------------------------------------------------------------
# 5. The two settings the checkout cannot carry.
#
# Both live in application/config/generated_overrides/, which is a bind mount,
# so this is a no-op on a redeploy that changed neither -- and the one thing
# that repairs a site whose domain was changed underneath it.
#
#   url_rewriting  files/.htaccess is the rewrite Concrete ships no .htaccess
#                  of its own for. With it false, every link the CMS generates
#                  carries `/index.php/` in it. Both work; one is a site.
#   canonical_url  see above.
$C5 c5:config set -g concrete.seo.url_rewriting true >/dev/null

if [ -n "$site_url" ]; then
    $C5 c5:config set -g site.sites.default.seo.canonical_url "$site_url" >/dev/null
fi

# What the last deploy compiled, against code a re-clone has just replaced:
# the compiled themes, the block-type cache and the route cache all live in
# application/files/cache, which is a bind mount and therefore *older* than the
# checkout. Optional -- a failure here is a cold cache, not a broken site.
$C5 c5:clear-cache >/dev/null 2>&1 || true

say "ready"
