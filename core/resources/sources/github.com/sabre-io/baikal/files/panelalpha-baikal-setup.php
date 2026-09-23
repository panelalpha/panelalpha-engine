<?php

/**
 * Baikal install / upgrade, headless.
 *
 * Runs inside the app container on the install and upgrade stages, as the
 * account uid, with /app as the working directory. It does what a human would
 * do in htdocs' install wizard, by calling the wizard's own code rather than
 * by reimplementing it:
 *
 *   BaikalAdmin\Controller\Install\Initialize   -> Baikal\Model\Config\Standard
 *                                                  + Baikal\Model\Config\Database
 *   BaikalAdmin\Controller\Install\Database     -> Flake\Core\Database\Sqlite
 *                                                  + Core/Resources/Db/SQLite/db.sql
 *                                                  + Specific/INSTALL_DISABLED
 *   BaikalAdmin\Controller\Install\VersionUpgrade (driven as-is on an upgrade)
 *
 * The two controllers themselves are \Formal\Form machinery around a POST, so
 * the models and the SQL loop they call are what is reused; the version
 * upgrade, which is a plain method and the part with real migration logic in
 * it, is instantiated and run unchanged.
 *
 * Why this runs at all: the wizard is first-visitor-wins. A deployed Baikal
 * with no config redirects *every* entry point -- index.php, dav.php,
 * admin/index.php -- to /admin/install/, and that page takes an admin password
 * from whoever asks first (Framework::installTool(), Baikal/Framework.php:51).
 * On an account with a public HTTPS domain, the window between the container
 * binding and the owner opening their browser is the whole exposure. Finishing
 * the install before Apache serves a request closes it.
 */

// ---------------------------------------------------------------------------
// A synthesized request. Flake is a web framework with no CLI entry point:
// Framework::bootstrap() reads SCRIPT_FILENAME, DOCUMENT_ROOT, REQUEST_URI and
// HTTP_HOST to work out PROJECT_BASEURI and PROJECT_URI (Flake/Framework.php,
// defineBaseUri()), and Model\Config\Standard's constructor reads SERVER_NAME.
// These are the values the real front controller would have been called with.
$host = getenv('SERVER_NAME') ?: 'localhost';
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = '/app/html';
$_SERVER['SCRIPT_FILENAME'] = '/app/html/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTPS'] = 'on';

define('BAIKAL_CONTEXT', true);
// The flag that keeps Flake\Framework::initDb() from demanding a database that
// does not exist yet, and -- when one does and the code has moved on -- makes
// it open the database so the upgrade below has a connection. Upstream's own
// condition, not a special case for this script (Flake/Framework.php:271).
define('BAIKAL_CONTEXT_INSTALL', true);
define('PROJECT_CONTEXT_BASEURI', '/');
define('PROJECT_PATH_ROOT', '/app/');

require '/app/vendor/autoload.php';

function say(string $line): void
{
    echo '[baikal] ' . $line . "\n";
}

function fail(string $line): never
{
    fwrite(STDERR, '[baikal] ' . $line . "\n");
    exit(1);
}

// PROJECT_PATH_CONFIG and PROJECT_PATH_SPECIFIC come out of BAIKAL_PATH_CONFIG
// and BAIKAL_PATH_SPECIFIC, which the compose override points at /data -- the
// bind mount from ~/.panelalpha that survives the clone (engine #173). Both
// have to exist and be writable before bootstrap(): Model\Config\Database's
// default sqlite_file is a property initializer built from
// PROJECT_PATH_SPECIFIC, so the constant has to be right, and
// Baikal\Core\Tools::assertBaikalIsOk() requires baikal.yaml to be *writable*
// and not merely readable.
foreach (['BAIKAL_PATH_CONFIG', 'BAIKAL_PATH_SPECIFIC'] as $key) {
    $dir = getenv($key);
    if (!is_string($dir) || $dir === '') {
        fail('BAIKAL_PATH_CONFIG and BAIKAL_PATH_SPECIFIC must both be set');
    }
    // Every use of these in Baikal concatenates a bare filename onto them --
    // PROJECT_PATH_CONFIG . "baikal.yaml" -- so a missing trailing slash makes
    // the constant point at a sibling file rather than into the directory.
    if (!str_ends_with($dir, '/')) {
        fail("{$key} must end with a slash, got '{$dir}'");
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        fail("{$dir} is not a writable directory");
    }
}

// A config file that parses but says nothing, when there is none yet.
//
// Not cosmetic, or not only: Flake\Framework::defineBaseUri() calls
// Yaml::parseFile() unguarded and catches the failure with `error_log($e)`,
// which prints a full ParseException and stack trace to stderr -- into the
// deploy log, on a deploy that is going fine. Two more lines follow it from
// Model\Config. An empty document takes every branch the missing file would:
// defineBaseUri finds no base_uri and falls through to SCRIPT_FILENAME,
// initDb() returns early because configured_version is unset, and
// Model\Config's constructor keeps its defaults.
$configFileEarly = getenv('BAIKAL_PATH_CONFIG') . 'baikal.yaml';
if (!file_exists($configFileEarly)) {
    file_put_contents($configFileEarly, "system: {}\ndatabase: {}\n");
    @chmod($configFileEarly, 0600);
}

\Flake\Framework::bootstrap();

$configFile = PROJECT_PATH_CONFIG . 'baikal.yaml';
$dbDir = PROJECT_PATH_SPECIFIC . 'db';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0700, true);
}
if (!is_dir($dbDir) || !is_writable($dbDir)) {
    // SQLite needs the directory, not only the file: -journal and -wal are
    // created beside it on every write.
    fail("{$dbDir} is not a writable directory");
}

$config = file_exists($configFile) ? \Symfony\Component\Yaml\Yaml::parseFile($configFile) : [];
$configured = $config['system']['configured_version'] ?? null;
$hasPassword = trim((string) ($config['system']['admin_passwordhash'] ?? '')) !== '';

// ---------------------------------------------------------------------------
// 1. The configuration file, on a first deploy only.
//
// Both halves are written through the wizard's own models, so the shape of
// baikal.yaml is upstream's and the admin password goes through
// BaikalAdmin\Core\Auth::hashAdminPassword() -- sha256('admin:<realm>:<pass>')
// -- rather than being hashed here. Model\Config\Standard::set() is what
// applies it, and it reads auth_realm out of its own $aData, so the realm has
// to be settled before the password. It is: this never changes auth_realm,
// because every user's digesta1 is md5(user:realm:pass) and changing the realm
// invalidates all of them at once.
if ($configured === null || !$hasPassword) {
    $adminPass = (string) getenv('BAIKAL_ADMIN_PASS');
    if (strlen($adminPass) < 12) {
        fail('BAIKAL_ADMIN_PASS is missing or too short; ~/.panelalpha/baikal-app.env should carry it');
    }

    $standard = new \Baikal\Model\Config\Standard();
    // UTC rather than the dist file's Europe/Paris: this value is what PHP's
    // date_default_timezone_set() is given on every request, and an account
    // has not told us where it is. Changeable in System Settings.
    $standard->set('timezone', 'UTC');
    $standard->set('cal_enabled', true);
    $standard->set('card_enabled', true);
    // Empty, which is what switches Sabre's IMipPlugin off
    // (Baikal/Core/Server.php:161 gates it on a non-empty value). The shared
    // PHP base image has no sendmail binary at all -- sendmail_path points at
    // /usr/sbin/sendmail, which does not exist -- so every scheduling invite
    // would be a mail() that silently fails inside a PUT. An owner who has a
    // relay can put an address back in System Settings.
    $standard->set('invite_from', '');
    $standard->set('admin_passwordhash', $adminPass);
    $standard->persist();

    $database = new \Baikal\Model\Config\Database();
    $database->set('backend', 'sqlite');
    $database->set('sqlite_file', $dbDir . '/db.sqlite');
    // Initialize.php:81 seeds this with md5(microtime() . rand()), which is
    // neither secret nor unpredictable. Nothing in Core/ reads it today, but
    // it is a field named encryption_key and it costs nothing to make it one.
    $database->set('encryption_key', bin2hex(random_bytes(16)));
    $database->persist();

    say('wrote ' . $configFile . ' (sqlite backend, admin password from ~/.panelalpha/baikal-app.env)');
} else {
    say('config already carries an admin password; left untouched');
}

@chmod($configFile, 0600);

$config = \Symfony\Component\Yaml\Yaml::parseFile($configFile);
$sqliteFile = $config['database']['sqlite_file'];

// ---------------------------------------------------------------------------
// 2. The schema.
//
// Straight out of BaikalAdmin\Controller\Install\Database::validateSQLiteConnection():
// ask Baikal\Core\Tools whether its own eight required tables are there, and
// if every one of them is missing, replay Core/Resources/Db/SQLite/db.sql
// statement by statement. Nothing here knows what a table looks like; the
// file is the repository's own and the split on ';' is the wizard's.
//
// The partial case is upstream's too and is deliberately an error rather than
// a repair: a database with some of these tables is either half-migrated or
// not Baikal's, and loading the schema over it would destroy whichever it is.
$db = new \Flake\Core\Database\Sqlite($sqliteFile);
$missing = \Baikal\Core\Tools::isDBStructurallyComplete($db);

if ($missing !== true) {
    $required = \Baikal\Core\Tools::getRequiredTablesList();
    if (count($required) !== count($missing)) {
        fail('database is partially populated; missing tables: ' . implode(', ', $missing)
            . '. Refusing to load the schema over it.');
    }

    $sql = file_get_contents(PROJECT_PATH_CORERESOURCES . 'Db/SQLite/db.sql');
    $statements = 0;
    foreach (explode(';', $sql) as $statement) {
        if (!trim($statement)) {
            continue;
        }
        $db->query($statement);
        $statements++;
    }
    say("loaded Core/Resources/Db/SQLite/db.sql ({$statements} statements) into " . $sqliteFile);

    if (\Baikal\Core\Tools::isDBStructurallyComplete($db) !== true) {
        fail('schema load finished but the database is still incomplete');
    }
} else {
    say('database already holds every required table');
}

// The file the account's calendars live in. 600 because the account uid is the
// only thing that ever opens it, and ~/.panelalpha is 700 anyway.
@chmod($sqliteFile, 0600);

// ---------------------------------------------------------------------------
// 3. The version upgrade, when the checkout has moved on.
//
// This is the case the recipe cannot skip. Every deploy re-clones master, so
// BAIKAL_VERSION can be ahead of the configured_version in a config file that
// outlived the checkout -- and when it is, Baikal\Framework::bootstrap()
// redirects *every* request to /admin/install/, which this recipe denies. So
// the upgrade has to happen here or the account is a redirect loop into a 403.
//
// Upstream's own controller is instantiated and rendered, which is exactly
// what /admin/install/?upgradeConfirmed does. Its render() is where the
// migration lives; the HTML it returns is stripped to text for the deploy log.
// $GLOBALS['DB'] is already open: initDb() opens it when BAIKAL_CONTEXT_INSTALL
// is set and the versions differ, which is this branch precisely.
$configured = $config['system']['configured_version'] ?? null;
if ($configured !== null && $configured !== BAIKAL_VERSION) {
    say("configured version {$configured}, code version " . BAIKAL_VERSION . '; running the upgrade');

    if (!isset($GLOBALS['DB'])) {
        $GLOBALS['DB'] = $db;
    }

    $upgrade = new \BaikalAdmin\Controller\Install\VersionUpgrade();
    $upgrade->execute();
    $html = $upgrade->render();

    $text = trim(preg_replace('/\n{2,}/', "\n", strip_tags(str_replace(['<br />', '</p>'], "\n", $html))));
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') {
            say('  ' . trim($line));
        }
    }

    if (stripos($html, 'has not been upgraded') !== false) {
        fail('version upgrade failed; see the lines above');
    }

    // render() leaves configured_version alone; the wizard relies on the
    // Standard form being saved afterwards. Say it here so the next request
    // does not bounce straight back into the install tool.
    $standard = new \Baikal\Model\Config\Standard();
    $standard->set('configured_version', BAIKAL_VERSION);
    $standard->persist();
    @chmod($configFile, 0600);
    say('configured_version is now ' . BAIKAL_VERSION);
}

// ---------------------------------------------------------------------------
// 4. Close the wizard.
//
// BaikalAdmin\Controller\Install\Database does this with touch() after a
// successful install, and install/index.php's last branch is the one that
// checks for it: with the file present and the versions in step, the page
// answers "Installation was already completed." and exits. The .htaccess this
// recipe installs denies the URL outright as well -- this is the same rule
// stated in the application, so neither one alone is load-bearing.
$disabled = PROJECT_PATH_SPECIFIC . 'INSTALL_DISABLED';
if (!file_exists($disabled) && !touch($disabled)) {
    fail('could not create ' . $disabled . ', so the install wizard would stay open');
}
// touch() honours the process umask, which is 0 in this container, so the file
// arrives 666. It sits in a 700 directory and nothing reads its contents, but a
// world-writable marker whose existence is a security control is not a thing to
// leave lying around.
@chmod($disabled, 0600);

say('ready: ' . BAIKAL_VERSION . ', sqlite at ' . $sqliteFile);
