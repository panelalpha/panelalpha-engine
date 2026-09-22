<?php

/**
 * @file
 * Brings a fresh checkout up as an installed Drupal site, on the install and
 * upgrade stages, before Apache binds.
 *
 * Copied into ~/project by the recipe's files/ directory on every deploy and
 * run by the entrypoint as `php /app/panelalpha-drupal.php`. It lives at the
 * project root, one level above the document root (/app/web), so it is not
 * web-reachable at all -- and its name additionally matches the base image's
 * `<FilesMatch "^(?:docker-compose\.ya?ml|panelalpha[-.])"> Require all denied`.
 *
 * Two jobs:
 *
 *   1. Write web/sites/default/settings.php. It is not persisted anywhere,
 *      because it does not have to be: everything in it is either a fact about
 *      the container (the database, which AppDatabase::provision() guarantees
 *      is the same on every deploy) or comes out of ~/.panelalpha/drupal/app.env
 *      (the hash salt). Regenerating beats restoring -- a restored settings.php
 *      can disagree with the credentials the engine actually provisioned, and a
 *      regenerated one cannot.
 *
 *   2. On an empty database, install Drupal. This is the security-relevant
 *      half: an installed-code, empty-database Drupal sends every request to
 *      core/install.php, which hands the site -- database, administrator
 *      account, everything -- to whoever loads the page first. Running here
 *      means that page is never served to anybody, because the entrypoint has
 *      not reached `exec panelalpha-serve` yet.
 *
 * Drupal's own installer is called rather than reimplemented:
 * install_drupal() with 'interactive' => FALSE, which is exactly what
 * Drupal\Core\Command\InstallCommand does for `drupal quick-start`, with the
 * MySQL driver namespace in place of its hard-coded SQLite one. No drush, and
 * that is deliberate: drush is not in composer.lock, so adding it would mean a
 * `composer require` resolving against packagist and packages.drupal.org on
 * every deploy, for one command.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const PA_APP = '/app';
const PA_ROOT = '/app/web';
const PA_SITE = 'sites/default';
const PA_SITE_DIR = PA_ROOT . '/' . PA_SITE;
const PA_PRIVATE = '/app/private';
const PA_MYSQL_NAMESPACE = 'Drupal\\mysql\\Driver\\Database\\mysql';

/** Written to stderr, which Docker collects and the deploy log shows. */
function pa_say(string $message): void
{
    fwrite(STDERR, '[panelalpha] drupal: ' . $message . "\n");
}

function pa_fail(string $message): never
{
    fwrite(STDERR, '[panelalpha] drupal: ' . $message . "\n");
    exit(1);
}

/** The Drupal version the checked-out code is, read without booting it. */
function pa_core_version(): string
{
    $file = PA_ROOT . '/core/lib/Drupal.php';
    $source = is_file($file) ? (string) file_get_contents($file) : '';

    return preg_match("/const VERSION = '([^']+)'/", $source, $m) === 1 ? $m[1] : 'unknown';
}

function pa_env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

// ---------------------------------------------------------------------------
// What the container was given.

$db = [
    'database' => pa_env('DB_DATABASE'),
    'username' => pa_env('DB_USERNAME'),
    'password' => pa_env('DB_PASSWORD'),
    'host' => pa_env('DB_HOST'),
    'port' => pa_env('DB_PORT', '3306'),
];
if ($db['database'] === '' || $db['username'] === '' || $db['host'] === '') {
    // `database: mysql` in panelalpha.yaml is what produces these, through
    // AppDatabase::provision(). Missing means the manifest did not match --
    // which is the failure mode worth naming, because everything else would
    // still look like it worked.
    pa_fail('no database in the environment; the manifest\'s `database: mysql` did not reach this container');
}

$hashSalt = pa_env('DRUPAL_HASH_SALT');
if ($hashSalt === '') {
    pa_fail('DRUPAL_HASH_SALT is empty; hooks/prepare.sh did not write ~/.panelalpha/drupal/app.env, or env_file did not reach this container');
}

// SERVERNAME is the account's main domain; APP_URL is the same name with a
// scheme. Both are written by the engine into the generated compose file.
$domain = pa_env('SERVERNAME', (string) parse_url(pa_env('APP_URL', 'http://localhost'), PHP_URL_HOST));
$domain = $domain !== '' ? $domain : 'localhost';

$adminUser = pa_env('DRUPAL_ADMIN_USER', 'admin');
$adminPass = pa_env('DRUPAL_ADMIN_PASS');
$adminMail = pa_env('DRUPAL_ADMIN_MAIL', 'admin@' . $domain);
$siteName = pa_env('DRUPAL_SITE_NAME', $domain);
$siteMail = pa_env('DRUPAL_SITE_MAIL', 'admin@' . $domain);

if (!is_file(PA_ROOT . '/autoload.php') || !is_file(PA_ROOT . '/index.php')) {
    // Both are produced by the build: autoload.php by `composer install`,
    // index.php by `composer drupal:scaffold`. Missing index.php in particular
    // means the scaffold did not run, which is the original bug this recipe
    // exists for -- so say which half is missing rather than dying inside
    // Drupal's bootstrap.
    pa_fail('web/autoload.php or web/index.php is missing; the composer build did not complete (scaffold did not run?)');
}

// ---------------------------------------------------------------------------
// The directories Drupal writes into.

// The two bind mounts. hooks/prepare.sh creates their sources on the host, the
// compose override mounts them here; if either is missing the deploy has gone
// wrong somewhere upstream and an install would put the account's uploads
// inside ~/project, where the next clone deletes them.
foreach ([PA_SITE_DIR . '/files', PA_PRIVATE] as $required) {
    if (!is_dir($required)) {
        pa_fail("{$required} is not a directory; the compose override's bind mount did not apply");
    }
    if (!is_writable($required)) {
        pa_fail("{$required} is not writable by this container's uid");
    }
}

// Inside the private mount, so it is outside the document root by construction.
// install_begin_request() requires this directory to *exist* before it will
// treat settings.php as complete (install.core.inc:391-400); with it missing,
// `settings_verified` is false and Drupal would run its database form instead
// of the install.
$configSync = PA_PRIVATE . '/config/sync';
@mkdir($configSync, 0700, true);
@mkdir(PA_PRIVATE . '/tmp', 0700, true);
// file_private_path below points here; Drupal's status report fails the site
// if it is missing or unwritable.
@mkdir(PA_PRIVATE . '/files', 0700, true);
if (!is_dir($configSync)) {
    pa_fail("could not create the config sync directory {$configSync}");
}

// ---------------------------------------------------------------------------
// settings.php.

$autoloader = require PA_ROOT . '/autoload.php';

// Where the driver's own classes are. A connection is opened before the module
// system exists, so nothing else would find them.
//
// Written as a literal rather than asked of Drupal: the helper that used to
// answer it, Database::findDriverAutoloadDirectory(), was deprecated in 10.2
// and removed in 11.0, and its replacement is a service. The path itself has
// not moved since the driver became a module in 10.0, and Drupal hard-codes
// exactly this string as its own fallback when the key is absent
// (Database::parseConnectionInfo(), Database.php:224-227).
$driverAutoload = 'core/modules/mysql/src/Driver/Database/mysql/';

/**
 * Renders settings.php.
 *
 * Credentials are read with getenv() at request time rather than written in as
 * literals. Two reasons, and the first is the one that matters: settings.php
 * sits inside the document root, and a server that ever serves it as text --
 * a broken PHP handler, a copy left as settings.php.bak -- then hands out the
 * account's database password. Second, it cannot go stale: there is exactly
 * one source for the credentials, the environment the engine wrote.
 *
 * Verified that getenv() reaches mod_php in this image: a probe page in the
 * document root returned APP_URL, SERVERNAME and HTTPS from the compose
 * `environment:` block.
 */
function pa_settings_php(array $db, string $driverAutoload, string $hashSalt, string $domain, string $configSync): string
{
    $quotedDomain = preg_quote($domain, '/');

    return <<<PHP
    <?php

    /**
     * @file
     * Generated by the PanelAlpha Drupal recipe on every deploy. Do not edit.
     *
     * ~/project is emptied before each clone (engine #173), so this file is
     * deleted and rewritten every time the project is deployed. Anything you
     * add here is lost on the next deploy; put it in a module, or in
     * configuration, both of which live in the database.
     *
     * Nothing in here is a secret. The credentials are read out of the
     * container environment, where the engine put them.
     */

    \$databases['default']['default'] = [
      'driver' => 'mysql',
      'namespace' => '{$db['namespace']}',
      // A connection is opened before the module system exists, so the driver's
      // own classes have to be findable without it.
      'autoload' => '{$driverAutoload}',
      'database' => getenv('DB_DATABASE'),
      'username' => getenv('DB_USERNAME'),
      'password' => getenv('DB_PASSWORD'),
      'host' => getenv('DB_HOST'),
      'port' => getenv('DB_PORT') ?: '3306',
      'prefix' => '',
      // The account's MySQL server is 8.x; utf8mb4 with the general collation is
      // what Drupal's own installer picks for it.
      'collation' => 'utf8mb4_general_ci',
    ];

    // Keys every session cookie, every one-time login link and every form
    // token. Generated once per account by hooks/prepare.sh into
    // ~/.panelalpha/drupal/app.env, which survives the clone; rotating it would
    // sign every user out and invalidate every password-reset mail in flight.
    \$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');

    // Outside the document root, on the ~/.panelalpha/drupal/private bind
    // mount, so these survive a redeploy and are unreachable over HTTP by
    // construction rather than by an .htaccess rule.
    \$settings['config_sync_directory'] = '{$configSync}';
    \$settings['file_private_path'] = '/app/private/files';
    \$settings['file_temp_path'] = '/app/private/tmp';

    // The public file system stays at its canonical place,
    // web/sites/default/files -- Drupal builds /sites/default/files/<path> URLs
    // for it and stores them in content. The compose override bind-mounts
    // ~/.panelalpha/drupal/files over it, so the directory is inside the
    // document root and the bytes are not inside ~/project.

    /**
     * Which Host headers this site will answer to.
     *
     * Unset, Drupal answers to any Host header, which is how password-reset
     * links get poisoned. Set, it has to include every name that reaches the
     * application -- including 127.0.0.1, which is what the container's own
     * healthcheck uses.
     *
     * Adding an addon domain to the account means adding it here; the simplest
     * way is to redeploy, which regenerates this file from SERVERNAME. To keep
     * a hand-written list, add it to a settings.local.php and include it below.
     */
    \$settings['trusted_host_patterns'] = [
      '^{$quotedDomain}\$',
      '^localhost\$',
      '^127\\.0\\.0\\.1\$',
    ];

    // update.php stays behind a login. FALSE is Drupal's own default and is
    // restated because this file is generated and an operator reading it should
    // not have to know what the default was.
    \$settings['update_free_access'] = FALSE;

    // The engine terminates TLS at its proxy and forwards over HTTP, so PHP
    // sees an http request for an https site. Without this Drupal builds
    // http:// absolute URLs -- in password-reset mails among other places --
    // and browsers block them as mixed content.
    if ((getenv('HTTPS') ?: '') === 'on' || (\$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
      \$_SERVER['HTTPS'] = 'on';
    }
    \$settings['reverse_proxy'] = TRUE;
    \$settings['reverse_proxy_trusted_headers'] =
      \\Symfony\\Component\\HttpFoundation\\Request::HEADER_X_FORWARDED_FOR
      | \\Symfony\\Component\\HttpFoundation\\Request::HEADER_X_FORWARDED_PROTO
      | \\Symfony\\Component\\HttpFoundation\\Request::HEADER_X_FORWARDED_PORT;

    // Anything the account owner wants to keep across deploys goes here. The
    // file is not created by this recipe and is not deleted by it either --
    // but it does live in ~/project, so it is deleted by the clone. It exists
    // for a settings snippet delivered by some other means.
    if (file_exists(__DIR__ . '/settings.local.php')) {
      include __DIR__ . '/settings.local.php';
    }
    PHP;
}

$db['namespace'] = PA_MYSQL_NAMESPACE;
$settingsFile = PA_SITE_DIR . '/settings.php';

// Drupal hardens settings.php to 0444 after an interactive install, and a
// crash-looping container would re-run this script against that file.
if (is_file($settingsFile)) {
    @chmod($settingsFile, 0644);
}
if (file_put_contents($settingsFile, pa_settings_php($db, $driverAutoload, $hashSalt, $domain, $configSync) . "\n") === false) {
    pa_fail("could not write {$settingsFile}");
}
// 0444 is what Drupal's own installer leaves behind, and what its status report
// checks for. The file is regenerated from scratch on every deploy, so nothing
// here needs it writable afterwards.
@chmod($settingsFile, 0444);
pa_say('wrote ' . $settingsFile);

// services.yml is optional -- Drupal falls back to default.services.yml -- but
// an account that wants to change a container parameter needs somewhere to do
// it, and the installer would otherwise create it with different content on
// the first boot and never again.
if (!is_file(PA_SITE_DIR . '/services.yml') && is_file(PA_SITE_DIR . '/default.services.yml')) {
    @copy(PA_SITE_DIR . '/default.services.yml', PA_SITE_DIR . '/services.yml');
}

// ---------------------------------------------------------------------------
// Is there a site in that database already?

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 15,
    ]);
} catch (PDOException $e) {
    // The entrypoint has already waited for the server (MysqlWait), so this is
    // a credentials or grants problem rather than a race.
    pa_fail('cannot connect to the database: ' . $e->getMessage());
}

// `key_value` holds the state collection, which is where install_task lands;
// its presence is Drupal's own definition of "there is a site here"
// (install_verify_completed_task() reads \Drupal::state()->get('install_task')).
$installed = false;
$statement = $pdo->query("SHOW TABLES LIKE 'key_value'");
if ($statement !== false && $statement->fetchColumn() !== false) {
    $row = $pdo->query(
        "SELECT value FROM key_value WHERE collection = 'state' AND name = 'install_task' LIMIT 1"
    )->fetchColumn();
    // Serialised PHP; the only value that means a finished install is 'done'.
    $installed = is_string($row) && str_contains($row, 'done');
}

if ($installed) {
    // A redeploy. The database is untouched -- content, users, configuration
    // and the administrator's current password all live there -- and
    // settings.php has just been rewritten, which is the only thing the clone
    // destroyed.
    //
    // The caches are the one thing that has to go. The compiled Twig templates
    // and the compiled service container are keyed to file paths and mtimes
    // under ~/project, and every one of those files is new: the clone replaced
    // them and Composer re-resolved vendor/. Drupal would notice eventually,
    // through its own mtime checks, but "eventually" is after it has already
    // served a request against a container definition pointing at a class map
    // that no longer matches.
    //
    // Emptying the cache_* tables and the compiled PHP directory is what
    // `drush cache:rebuild` does when the site is too broken to bootstrap, and
    // it is safe by definition: a cache table holds nothing that is not
    // derivable. key_value and key_value_expire are deliberately not touched --
    // those are state, not cache.
    $cleared = 0;
    foreach ($pdo->query("SHOW TABLES LIKE 'cache\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', (string) $table) . '`');
        $cleared++;
    }
    // The compiled Twig templates, inside the persistent files mount -- so they
    // do survive the clone, which is exactly why they have to be removed by
    // hand. The path is asserted rather than trusted: this is an rm -rf, and
    // PA_SITE_DIR is a constant precisely so it cannot be anything else.
    $php = PA_SITE_DIR . '/files/php';
    if ($php === '/app/web/sites/default/files/php' && is_dir($php)) {
        exec('rm -rf ' . escapeshellarg($php));
    }
    pa_say("site already installed; rewrote settings.php and cleared {$cleared} cache tables");
    // Said out loud on every redeploy, because it is the one thing this script
    // deliberately does not do. The clone takes a fresh `11.x` and Composer
    // re-resolves the lock that came with it, so a redeploy weeks apart can
    // move Drupal core -- and a core that moved has schema updates waiting.
    // Running them unattended over a customer's data is a decision the
    // operator owns, the same reason the engine never seeds a database, and
    // Drupal's CLI has no updatedb command to run them with. /update.php,
    // signed in as the administrator, is the supported path.
    pa_say('code is ' . pa_core_version() . '; if that has moved since the last deploy, sign in and run /update.php');
    exit(0);
}

// ---------------------------------------------------------------------------
// Install.

if ($adminPass === '') {
    pa_fail('DRUPAL_ADMIN_PASS is empty and the site is not installed; refusing to install a site with no administrator password');
}

// install_drupal() builds URLs and reads the request even under CLI, through
// Request::createFromGlobals(). Left alone it would see no host at all;
// pointing it at the account's own domain means anything that is captured at
// install time is captured correctly.
$_SERVER['HTTP_HOST'] = $domain;
$_SERVER['SERVER_NAME'] = $domain;
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = PA_ROOT . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_SOFTWARE'] = 'panelalpha-install';

// install_drupal() resolves 'sites/default' and the profile directory relative
// to the working directory, the same way core/scripts does.
chdir(PA_ROOT);

require_once PA_ROOT . '/core/includes/install.core.inc';

$langcode = pa_env('DRUPAL_LANGCODE', 'en');

/*
 * A recipe, not a profile -- on Drupal 11 those are no longer the same site.
 *
 * `core/profiles/standard/standard.info.yml` on 11.4-dev installs a module
 * list and `themes: [default_admin]`, and nothing else: no permissions, no
 * text formats, no roles. Installed from that profile alone the site comes up
 * and answers **403 Access denied to anonymous users**, because nobody has
 * granted `access content` -- measured on this host before this block existed,
 * and it looks exactly like the bug this recipe was written to fix while
 * having nothing to do with it.
 *
 * `core/recipes/standard/recipe.yml` is where the rest went: it grants
 * `access content` to anonymous and authenticated, sets the default theme,
 * creates the administrator and content_editor roles, installs the CKEditor
 * text formats and pulls in ten further recipes. That is what upstream now
 * means by "a standard site", and `dr install core/recipes/standard` is how
 * core's own CLI spells it (InstallCommand::execute() accepts either).
 *
 * The profile is kept as a fallback so this script still installs a site on a
 * Drupal that predates the split, where the recipe directory does not exist.
 */
$recipe = pa_env('DRUPAL_RECIPE', 'core/recipes/standard');
$useRecipe = $recipe !== '' && is_dir(PA_ROOT . '/' . $recipe);
$profile = $useRecipe ? '' : pa_env('DRUPAL_PROFILE', 'standard');

$parameters = [
    'interactive' => false,
    'site_path' => PA_SITE,
    'parameters' => [
        // '' becomes FALSE inside install_begin_request() (install.core.inc:321),
        // which is what makes the recipe tasks replace the profile tasks
        // (install.core.inc:823).
        'profile' => $profile,
        'langcode' => $langcode,
    ] + ($useRecipe ? ['recipe' => $recipe] : []),
    'forms' => [
        // Skipped, because settings.php already carries a valid connection and
        // a hash salt and the config sync directory exists -- which is what
        // install_begin_request() checks for `settings_verified`
        // (install.core.inc:397-400). Passed anyway so that a settings.php this
        // script failed to write does not turn into an interactive prompt on a
        // non-interactive install.
        'install_settings_form' => [
            'driver' => PA_MYSQL_NAMESPACE,
            PA_MYSQL_NAMESPACE => [
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => $db['password'],
                'host' => $db['host'],
                'port' => $db['port'],
                'prefix' => '',
            ],
        ],
        'install_configure_form' => [
            'site_name' => $siteName,
            'site_mail' => $siteMail,
            'account' => [
                'name' => $adminUser,
                'mail' => $adminMail,
                'pass' => ['pass1' => $adminPass, 'pass2' => $adminPass],
            ],
            'enable_update_status_module' => true,
            // Checkboxes::valueCallback() wants NULL rather than FALSE to mean
            // "unchecked" on a programmatic submission -- core's own
            // InstallCommand carries the same comment. FALSE here silently
            // enables the mail.
            'enable_update_status_emails' => null,
        ],
    ],
];

pa_say(sprintf(
    'installing Drupal %s from %s into %s as %s',
    pa_core_version(),
    $useRecipe ? "the {$recipe} recipe" : "the {$profile} profile",
    $db['database'],
    $adminUser
));
$started = microtime(true);

try {
    install_drupal($autoloader, $parameters);
} catch (\Throwable $e) {
    pa_fail('install failed: ' . get_class($e) . ': ' . $e->getMessage());
}

$elapsed = round(microtime(true) - $started, 1);
$peak = round(memory_get_peak_usage(true) / 1048576);
pa_say("installed in {$elapsed}s (peak {$peak} MB)");

/*
 * The content types, which `core/recipes/standard` deliberately does not
 * create: it is a site recipe, and on 11.x the node types are recipes of their
 * own. Without them Drupal comes up with the node module installed, no
 * bundles, and nowhere to put an article -- which is a CMS that cannot hold
 * content until its owner builds a content type by hand. Article and Basic
 * page are what every Drupal before 11.2 shipped, so this restores the thing
 * the account owner is expecting rather than inventing one.
 *
 * Applied through Drupal's own CLI in a separate process rather than through
 * RecipeRunner in this one: install_drupal() leaves a container built for the
 * installer, and a recipe is a config import that wants an ordinary
 * bootstrapped site. Best-effort -- a recipe that will not apply is worth a
 * line in the deploy log, not a container that refuses to start over a content
 * type.
 *
 * Set DRUPAL_RECIPES_EXTRA to '' in the project's env_vars to skip this, or to
 * a space-separated list of recipe paths to choose your own.
 */
$extra = pa_env('DRUPAL_RECIPES_EXTRA', 'core/recipes/page_content_type core/recipes/article_content_type');
foreach (preg_split('/\s+/', trim($extra), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $path) {
    if (!is_dir(PA_ROOT . '/' . $path)) {
        pa_say("skipping recipe {$path}: not in this checkout");
        continue;
    }
    $output = [];
    $status = 0;
    exec(
        'php ' . escapeshellarg(PA_APP . '/panelalpha-dr.php')
        . ' recipe:apply ' . escapeshellarg($path) . ' --no-interaction 2>&1',
        $output,
        $status
    );
    pa_say($status === 0
        ? "applied recipe {$path}"
        : "recipe {$path} failed (" . $status . '): ' . implode(' | ', array_slice($output, -3)));
}

pa_say("Sign in at https://{$domain}/user/login as {$adminUser}");
pa_say('the password is in ~/.panelalpha/drupal/drupal-admin-credentials.txt');
