#!/bin/bash
# Runs inside the account's container on the install and upgrade stages,
# before Apache binds. Everything here is idempotent: the upgrade stage
# replays it on every redeploy, and a crash-looping container would replay it
# on every boot.
#
# It lives in panelalpha/ rather than at the top of the checkout because
# Shopware's document root is public/ -- nothing here is web-reachable.
set -e
cd /app

PHP='php -d memory_limit=1024M'
CONSOLE="${PHP} bin/console"
export COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[shopware] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi
DB_PORT="${DB_PORT:-3306}"

# ---------------------------------------------------------------------------
# 1. vendor/autoload_runtime.php, which Composer was not allowed to write.
#
# Engine defect #168. The php manifest installs with `--no-plugins` -- a
# plugin is arbitrary PHP out of a customer repository and the install runs on
# the host daemon -- and PhpHostBuild::mayRunPlugins() lifts that only for a
# project whose composer.lock pins nothing but installer plugins.
# shopware/shopware commits no composer.lock at all (/composer.lock is in its
# .gitignore), so the exemption cannot fire and symfony/runtime -- which
# composer.json's config.allow-plugins explicitly trusts -- is installed and
# never activated.
#
# symfony/runtime writes vendor/autoload_runtime.php on POST_AUTOLOAD_DUMP,
# and that file is line 11 of public/index.php and line 18 of bin/console.
# Without it every request and every console command is a fatal "Failed to
# open stream: No such file or directory". Verified on this engine: a plain
# `composer install --no-dev --no-scripts --no-plugins --optimize-autoloader`
# of this repository leaves no vendor/autoload_runtime.php.
#
# `dump-autoload` re-fires that one event and writes no new code of its own.
# Here rather than in the build because the plugins are the account's to run,
# in the account's own container.
#
# --no-scripts still: composer.json's post-install-cmd is `composer bin all
# install` (bamarni/composer-bin-plugin), which resolves the repository's
# static-analysis tooling -- phpstan, rector, php-cs-fixer, a
# backward-compatibility checker. None of it is needed to serve a shop and it
# is another few hundred megabytes of the account's quota.
if [ ! -f vendor/autoload_runtime.php ]; then
    echo "[shopware] writing vendor/autoload_runtime.php (engine defect #168)"
    composer dump-autoload --no-dev --optimize --no-interaction --no-scripts
fi

# ---------------------------------------------------------------------------
# 2. public/.htaccess, which the clone does not carry.
#
# .gitignore ignores /public/* and un-ignores exactly two paths, index.php and
# .htaccess.dist. So a fresh checkout has no .htaccess, and without it Apache
# has no rewrite: `/` reaches index.php through DirectoryIndex and every other
# route -- /account/login, /checkout/cart, /admin -- is a 404 from the
# filesystem. SystemInstallCommand::ensureHtaccessExists() does this copy too,
# but only on a first install; a redeploy re-clones the tree and would lose it.
#
# The base image serves this document root with `AllowOverride All`, so the
# file is read.
if [ ! -f public/.htaccess ] && [ -f public/.htaccess.dist ]; then
    cp public/.htaccess.dist public/.htaccess
    echo "[shopware] restored public/.htaccess from .htaccess.dist"
fi

# ---------------------------------------------------------------------------
# 3. DATABASE_URL, which is the one value that cannot be known before now.
#
# The engine hands the container DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/
# DB_PASSWORD for the database it provisioned on the account's own MySQL
# server. Shopware reads a single DATABASE_URL DSN instead
# (DatabaseConnectionInformation::fromEnv()), so it is assembled here.
#
# .env.local rather than .env: the generated compose file carries
# `env_file: ['.env']`, which compose reads when the container is created, so
# every line of .env is a real process environment variable by the time this
# runs -- and Symfony's Dotenv::populate() never overwrites one of those. A
# DATABASE_URL written into .env would therefore not take effect until the
# *next* container creation. .env.local is read at runtime, is what Symfony
# documents for per-install values, and is what hooks/prepare.sh deliberately
# left out of .env so that nothing shadows it.
#
# Rewritten on every deploy, not written once: a redeploy re-clones ~/project
# and .env.local goes with it, while the database and its password survive.
${PHP} -r '
    $url = sprintf(
        "mysql://%s:%s@%s:%s/%s",
        rawurlencode((string) getenv("DB_USERNAME")),
        rawurlencode((string) getenv("DB_PASSWORD")),
        getenv("DB_HOST"),
        getenv("DB_PORT") ?: "3306",
        rawurlencode((string) getenv("DB_DATABASE"))
    );
    $file = "/app/.env.local";
    $existing = is_file($file) ? (string) file_get_contents($file) : "";
    $existing = (string) preg_replace("/^DATABASE_URL=.*$\n?/m", "", $existing);
    $old = umask(077);
    file_put_contents($file, $existing . "DATABASE_URL=" . $url . "\n");
    umask($old);
    chmod($file, 0600);
'

# The shop's public address. The engine sets APP_URL (and URL, BASE_URL,
# SITE_URL, SERVERNAME ...) to the account's own origin in
# PublicUrlEnvironment::for(). Shopware is strict about it: a request whose
# Host does not match a sales channel domain is answered with HTTP 400 and a
# "Shopware Domain Mapping Misconfiguration" page, not a redirect.
SHOP_URL="${APP_URL:-}"
if [ -z "${SHOP_URL}" ]; then
    echo "[shopware] APP_URL is empty; the storefront cannot be given a domain" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Install, or bring an install that is already there up to date.
#
# "Already there" is a fact about the database -- the `sales_channel` table --
# and not about install.lock, which a redeploy's re-clone wipes along with the
# rest of ~/project. A redeploy keeps the account's MySQL database, so this is
# the branch that matters most often.
if ${PHP} -r '
    try {
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            (string) getenv("DB_USERNAME"),
            (string) getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "[shopware] cannot reach the database: " . $e->getMessage() . "\n");
        exit(2);
    }
    exit($pdo->query("SHOW TABLES LIKE \"sales_channel\"")->fetchColumn() === false ? 1 : 0);
'; then
    echo "[shopware] already installed; migrating and refreshing assets"
    $CONSOLE database:migrate --all core --no-interaction
    $CONSOLE database:migrate-destructive --all --version-selection-mode all core --no-interaction || true

    # The checkout is new, so public/bundles is empty and the theme's compiled
    # output under public/theme is gone with it.
    $CONSOLE assets:install --no-interaction

    # If the account's address changed between deploys, every request would
    # otherwise be the 400 domain-mapping page. Only rewritten when it really
    # differs, and only when there is exactly one domain to be unambiguous
    # about -- a shop whose owner has since added domains of their own is left
    # alone.
    ${PHP} -r '
        $url = rtrim((string) getenv("APP_URL"), "/");
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            (string) getenv("DB_USERNAME"),
            (string) getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $rows = $pdo->query("SELECT LOWER(HEX(id)) AS id, url FROM sales_channel_domain")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || rtrim((string) $rows[0]["url"], "/") === $url) {
            exit(0);
        }
        $stmt = $pdo->prepare("UPDATE sales_channel_domain SET url = :url WHERE id = UNHEX(:id)");
        $stmt->execute(["url" => $url, "id" => $rows[0]["id"]]);
        fwrite(STDOUT, "[shopware] sales channel domain re-pointed to " . $url . "\n");
    '

    $CONSOLE theme:compile --sync --no-interaction || true
    $CONSOLE cache:clear --no-interaction
else
    # A first install. The password was generated per account by
    # hooks/prepare.sh before anything could serve a page.
    if [ ! -r .panelalpha-admin-password ]; then
        echo "[shopware] .panelalpha-admin-password is missing; hooks/prepare.sh writes it" >&2
        exit 1
    fi
    password=$(cat .panelalpha-admin-password)
    if [ -z "${password}" ]; then
        echo "[shopware] the generated administrator password is empty" >&2
        exit 1
    fi

    echo "[shopware] installing"
    # No --basic-setup. That flag is the documented one-shot install and
    # SystemInstallCommand.php:130-137 hard-codes what it creates:
    # `user:create admin --admin --password shopware`. On a public HTTPS domain
    # that is a published credential. The three things --basic-setup would have
    # done are done below, with a generated password and this account's own URL.
    #
    # --skip-first-run-wizard: the wizard is a tour of Shopware's own
    # marketplace and account services, and it blocks the administration until
    # it is dismissed.
    $CONSOLE system:install \
        --create-database \
        --force \
        --no-interaction \
        --skip-first-run-wizard \
        --shop-locale=en-GB \
        --shop-currency=EUR

    $CONSOLE user:create admin \
        --admin \
        --password="${password}" \
        --firstName=Admin \
        --lastName=Shopware \
        --email="${SHOPWARE_ADMIN_EMAIL:-admin@example.com}" \
        --no-interaction

    $CONSOLE sales-channel:create:storefront \
        --name=Storefront \
        --url="${SHOP_URL}" \
        --isoCode=en-GB \
        --no-interaction

    # Assigns the Storefront theme to the new sales channel and compiles it.
    # Not `allowedToFail`: a sales channel with no theme renders nothing.
    $CONSOLE theme:change --all --sync Storefront --no-interaction
fi

# ---------------------------------------------------------------------------
# 5. install.lock, which is the difference between a shop and an open installer.
#
# public/index.php:26-35 redirects every request to /installer and boots
# InstallerKernel whenever install.lock is absent. That installer asks for a
# database and then creates an administrator -- so on a public domain, an
# absent install.lock is first-visitor-wins control of the shop.
#
# SystemInstallCommand writes it through SystemLocker on a first install, but
# install.lock is in .gitignore and ~/project is re-cloned on every redeploy,
# so on the upgrade path the file is gone while the shop behind it is live and
# full of orders. This is the line that closes it again.
if [ ! -f install.lock ]; then
    date -u +%Y-%m-%dT%H:%M:%S%z > install.lock
    echo "[shopware] wrote install.lock (the web installer at /installer is now closed)"
fi
chmod 600 .panelalpha-admin-password 2>/dev/null || true
chmod 600 .env .env.local 2>/dev/null || true

# ---------------------------------------------------------------------------
# 6. Say plainly whether the administration is there.
#
# panelalpha/build-assets.sh skips the Administration's Vite build on an
# account smaller than ~2.7GB, because it cannot be made to fit; see the
# measurements in that file. The storefront does not depend on it, so the shop
# works either way, but /admin is a blank page without it and an operator
# should not have to find that out by clicking.
if [ ! -d src/Administration/Resources/public/administration ]; then
    echo "[shopware] NOTE: the Administration bundle was not built, so /admin will not load."
    echo "[shopware] Its Vite build needs about 2.7GB; this account has less. The storefront,"
    echo "[shopware] the customer account area and the checkout are unaffected."
fi

echo "[shopware] ready at ${SHOP_URL} -- administrator 'admin', password in ~/project/.panelalpha-admin-password"
