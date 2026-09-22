<?php

/**
 * Installs OpenEMR, or repairs the config of one that is already installed.
 *
 * Runs in the container on the install and upgrade stages, from
 * panelalpha-setup.sh, before Apache binds. Idempotent by design: it asks the
 * database what state it is in rather than trusting anything on disk, because
 * on this platform the disk is re-cloned and the database is not.
 *
 * Nothing here reimplements OpenEMR's installer. `Installer::quick_install()`
 * is the same method contrib/util/installScripts/InstallerAuto.php and
 * upstream's docker/release/auto_configure.php call, with the same settings
 * array; this file only decides *whether* to call it, and supplies values a
 * checkout cannot know.
 *
 * The name is load-bearing: it sits in the document root, and the generated
 * vhost denies `^(?:docker-compose\.ya?ml|panelalpha[-.])`.
 */

declare(strict_types=1);

use OpenEMR\BC\ServiceContainer;

$root = __DIR__;
require_once $root . '/vendor/autoload.php';

/**
 * Every message this script prints goes to stderr, and that is not a style
 * choice -- it is the difference between an install that works and one that
 * dies two thirds of the way through.
 *
 * `Installer::install_gacl()` constructs `OpenEMR\Gacl\Gacl`, whose
 * constructor calls `DatabaseConnectionFactory::detectConnectionPersistenceFromGlobalState()`,
 * which asks for the active session. Symfony's `NativeSessionStorage::start()`
 * -- reached through OpenEMR's `ReadAndCloseNativeSessionStorage` -- refuses
 * with `Failed to start the session because headers have already been sent`
 * whenever `headers_sent()` is true, and under the CLI SAPI `headers_sent()`
 * becomes true on the first byte PHP writes to standard output. One `echo`
 * anywhere earlier in the process is enough.
 *
 * Measured: a plain `echo "[openemr] installing into ..."` before
 * quick_install() killed the install with an uncaught RuntimeException out of
 * `Gacl->__construct()`, after the 700-table schema, the language pack, the
 * globals and the version row had all been written -- so the account had a
 * half-built database, no ACLs and no administrator, and the deploy rolled
 * back. `fwrite(STDERR, ...)` bypasses PHP's output layer entirely and leaves
 * `headers_sent()` false. Upstream's own two CLI entry points
 * (contrib/util/installScripts/InstallerAuto.php,
 * docker/release/auto_configure.php) print nothing at all before the call,
 * which is the same rule arrived at by not needing to say anything.
 *
 * `error_log()` is also safe, which is why the Installer's own SystemLogger
 * (Monolog's ErrorLogHandler) can log through quick_install() without
 * tripping this.
 *
 * Both streams land in `docker compose logs app` either way.
 */
function pa_say(string $message): void
{
    fwrite(STDERR, "[openemr] {$message}\n");
}

function pa_env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function pa_fail(string $message): never
{
    fwrite(STDERR, "[openemr] {$message}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. The module Composer put in the wrong place.
//
// claimrevolution/oe-module-claimrev-connect is `"type": "openemr-module"`,
// and the installer that knows what that means is
// openemr/oe-module-installer-plugin -- a composer-plugin whose
// CustomModuleInstaller::getInstallPath() returns
// `interface/modules/custom_modules/<name>`. The php manifest installs with
// `--no-plugins`, because a plugin is arbitrary PHP out of a customer
// repository and the install runs on the host daemon, and
// PhpHostBuild::mayRunPlugins() lifts that only for the five installer plugins
// it names. So Composer used its own LibraryInstaller and the module is in
// vendor/, where OpenEMR's module scanner never looks.
//
// Copied rather than symlinked: a symlink into vendor/ would break the moment
// a redeploy re-resolved dependencies. Cosmetic -- this is optional billing
// integration, not a boot requirement -- so a failure here is reported and
// does not stop the install.
$modules = [
    'claimrevolution/oe-module-claimrev-connect' => 'oe-module-claimrev-connect',
];
foreach ($modules as $package => $name) {
    $from = $root . '/vendor/' . $package;
    $to = $root . '/interface/modules/custom_modules/' . $name;
    if (is_dir($from) && !is_dir($to)) {
        exec('cp -a ' . escapeshellarg($from) . ' ' . escapeshellarg($to), $out, $code);
        pa_say($code === 0
            ? "moved {$package} out of vendor/ into interface/modules/custom_modules/"
            : "warning: could not copy {$package} into custom_modules/");
    }
}

// ---------------------------------------------------------------------------
// 2. The settings, from the database the engine provisioned.
//
// `database: mysql` in panelalpha.yaml is what asks for it: AppDatabase
// creates a database and user on the account's own MySQL server -- visible in
// the panel, openable in phpMyAdmin, included in the account's backup -- and
// the generated compose file passes the credentials as DB_*. The password is
// kept in the account's encrypted details, so these are stable across
// redeploys.
//
// no_root_db_access is the whole reason this fits: the account has no MySQL
// root, and quick_install() skips the root connection, CREATE DATABASE,
// CREATE USER and GRANT entirely when it is set, going straight to the user
// connection it was handed.
$host = pa_env('DB_HOST');
$database = pa_env('DB_DATABASE');
$username = pa_env('DB_USERNAME');
$password = pa_env('DB_PASSWORD');
$port = pa_env('DB_PORT', '3306');

if ($host === '' || $database === '' || $username === '') {
    pa_fail("no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?");
}

// Generated by hooks/prepare.sh, per account, 0600. Never a default: upstream's
// own automation ships `iuserpass = 'pass'`.
$passwordFile = $root . '/.panelalpha-admin-password';
if (!is_file($passwordFile)) {
    $generated = substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes(24))), 0, 24);
    $old = umask(0077);
    file_put_contents($passwordFile, $generated . "\n");
    umask($old);
    chmod($passwordFile, 0600);
}
$adminPassword = trim((string) file_get_contents($passwordFile));
if ($adminPassword === '') {
    pa_fail('.panelalpha-admin-password is empty');
}

$settings = [
    'site' => 'default',
    'server' => $host,
    'port' => $port,
    // Only ever used to build the host part of a CREATE USER, which
    // no_root_db_access skips. Kept for shape.
    'loginhost' => 'localhost',
    'root' => '',
    'rootpass' => '',
    'login' => $username,
    'pass' => $password,
    'dbname' => $database,
    // utf8mb4 throughout. Installer::create_database() would default to this
    // anyway; with no_root_db_access it does not run, and the engine created
    // the database, so this only reaches SET NAMES.
    'collate' => 'utf8mb4_general_ci',
    'no_root_db_access' => '1',
    'iuser' => 'admin',
    'iuname' => 'Administrator',
    'iufname' => '',
    'igroup' => 'Default',
    'iuserpass' => $adminPassword,
    // Advanced multi-site options. Empty means "no".
    'source_site_id' => '',
    'clone_database' => '',
    // Set, this downloads the daily translation set over the network at
    // install time. The in-tree one is what a reproducible deploy wants.
    'development_translations' => '',
];

// ---------------------------------------------------------------------------
// 3. Installed, or not?
//
// Asked of the database, not of sites/default/sqlconf.php, and that is the
// point. sqlconf.php is a *committed* file -- it ships pointing at
// openemr:openemr@localhost with `$config = 0` -- and a redeploy re-clones
// ~/project, so the file says "not installed" on every deploy after the first
// while the database still holds the practice's records. Trusting it would
// re-run the installer over live patient data.
//
// users_secure is the table the login path reads and one of the last things
// quick_install() writes (add_initial_user, after the schema, the globals and
// the version row), so its presence means an install that got far enough to
// have an administrator.
$mysqli = null;
$lastError = '';
for ($attempt = 1; $attempt <= 10; $attempt++) {
    try {
        $mysqli = @new mysqli($host, $username, $password, $database, (int) $port);
        if ($mysqli->connect_errno === 0) {
            break;
        }
        $lastError = $mysqli->connect_error ?? 'unknown error';
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }
    $mysqli = null;
    // The engine provisions the database before it writes the compose file, so
    // it is there. This is for the seconds a busy host can take to accept the
    // connection, not for a database that does not exist.
    sleep(3);
}
if ($mysqli === null) {
    pa_fail("cannot reach {$database}@{$host}:{$port} as {$username}: {$lastError}");
}

$result = $mysqli->query("SHOW TABLES LIKE 'users_secure'");
$installed = $result !== false && $result->num_rows > 0;
$mysqli->close();

$installer = new Installer($settings, ServiceContainer::getLogger());

if ($installed) {
    // Only the config file, and only because the re-clone reset it. The
    // Installer's own writer, so the file stays byte-for-byte what OpenEMR
    // expects -- including `$config = 1`, which is what index.php reads to
    // decide between the login page and setup.php.
    if (!$installer->write_configuration_file()) {
        pa_fail('already installed, but could not rewrite sites/default/sqlconf.php: ' . $installer->error_message);
    }
    pa_say("already installed; rewrote sites/default/sqlconf.php for {$database}@{$host}");
    pa_say('schema migrations are NOT run automatically -- see the recipe README');
    exit(0);
}

// A fresh account. This is the long one: Installer::load_file() issues one
// mysqli_query per statement, and the language pack alone is 237,511 of them.
pa_say("installing into {$database}@{$host}: 6,408 schema statements then 237,511 language inserts, one query each");
$started = microtime(true);

if (!$installer->quick_install()) {
    pa_fail('install failed: ' . $installer->error_message);
}

pa_say(sprintf(
    "installed in %.0fs; administrator 'admin', password in ~/project/.panelalpha-admin-password",
    microtime(true) - $started
));
