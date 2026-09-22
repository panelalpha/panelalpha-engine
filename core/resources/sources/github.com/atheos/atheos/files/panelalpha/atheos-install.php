<?php

/**
 * Written by PanelAlpha. Runs on the install and upgrade stages, in the
 * container, before Apache binds. Two jobs.
 *
 * 1. Write config.php.
 *
 *    Atheos has no environment configuration at all: Common::initialize()
 *    require_once's BASE_PATH . "/config.php" and falls back to
 *    Common::$configDefaults for anything it does not define. Upstream's
 *    installer writes that file once, at the end of setup, and a redeploy
 *    re-clones ~/project and deletes it (engine#173) -- so it is written here,
 *    every deploy, rather than once.
 *
 *    Three things in it are not upstream's defaults:
 *
 *      DATA and WORKSPACE     /data/data and /data/workspace, the bind mount,
 *                             instead of BASE_PATH . "/data" and
 *                             BASE_PATH . "/workspace" inside the checkout.
 *                             The first holds the password hashes, the project
 *                             list and every per-user setting; the second holds
 *                             the customer's source code. Both are what a
 *                             redeploy would otherwise delete.
 *      TIMEZONE               "UTC" rather than the default `false`, which
 *                             reaches date_default_timezone_set() as "" and
 *                             prints `Timezone ID '' is invalid` into the page.
 *      display_errors off     the shared PHP image runs display_errors=1 with
 *                             no error_reporting set, and the vendored
 *                             matthiasmullie/minify emits twenty
 *                             `Use of "parent" in callables is deprecated`
 *                             lines into <head> the first time it builds the
 *                             asset bundles. Both are why this repository's
 *                             verdict was serving-php_error.
 *
 * 2. Create the first user, once, from a password generated per account.
 *
 *    Atheos's setup is first-visitor-wins twice over: index.php serves
 *    components/install/view.php whenever the user and project files are
 *    absent, and components/install/process.php is a POST endpoint that checks
 *    no session, creates the first user with ["configure","read","write"] and
 *    userACL "full", and logs the caller straight in. In a web IDE "configure"
 *    means the Macro component, which runs shell commands, and write access to
 *    the application's own document root.
 *
 *    process.php is the same code the wizard's last step posts to, called with
 *    the same fields, so nothing is reimplemented. Its own guard reads
 *    BASE_PATH . "/data/", not DATA, which is why the stub shipped at
 *    data/users.json.php has to be lifted for the one call and put back
 *    afterwards -- see data/README.panelalpha.md.
 *
 * Idempotent: the install is skipped entirely once DATA/users.json.php exists,
 * so the upgrade stage re-running this over a data directory that survived the
 * redeploy changes nothing but config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Errors to stderr, before common.php is loaded.
 *
 * The shared PHP image runs display_errors=1, and common.php emits a notice on
 * the way past (TIMEZONE defaults to `false`). On the CLI SAPI any output at
 * all makes headers_sent() true, so that notice on stdout is enough to make
 * the session_start() twenty lines later fail, leave $_SESSION unset, and kill
 * this script with
 *
 *   Uncaught TypeError: array_key_exists(): Argument #2 ($array) must be of
 *   type array, null given in /app/traits/exchange.php:75
 *
 * -- which is what happens to a web request too, and is the whole reason this
 * application needed a recipe. stderr keeps the diagnostics in `docker logs`
 * and stdout empty.
 */
ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');

$base = '/app';
$dataDir = getenv('ATHEOS_DATA_DIR') ?: '/data';

$DATA = $dataDir . '/data';
$WORKSPACE = $dataDir . '/workspace';

$configPath = $base . '/config.php';
$stubPath = $base . '/data/users.json.php';
$stub = "<?php/*|\n{}\n|*/?>";

//////////////////////////////////////////////////////////////////////// config

/**
 * Written before the installer runs and again after it, because
 * components/install/process.php writes its own config.php as its last act and
 * that one points DATA and WORKSPACE back into the checkout.
 */
function writeConfig(string $path, string $data, string $workspace): void
{
    $config = <<<PHP
<?php

//////////////////////////////////////////////////////////////////////////////80
// Written by PanelAlpha on every deploy. Do not edit: a redeploy re-clones the
// project and this file is regenerated. Per-account settings belong in the
// panel's environment variables.
//////////////////////////////////////////////////////////////////////////////80

// The shared PHP image runs display_errors=1 with no error_reporting set, and
// this application is served from its own repository root -- so a notice puts
// the absolute path of a file inside the account into the page a visitor is
// reading. Errors are logged to the container's stderr (`docker logs`, and the
// deploy log) and never rendered.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
error_reporting(E_ALL);

// Set before Common::startSession() calls session_start(), which is the only
// chance to set them. PHP's own defaults are httponly=0, samesite="" and
// use_strict_mode=0, and the session cookie is the whole of this application's
// authentication.
//
// The cookie is named md5(BASE_PATH), and BASE_PATH is /app on every account
// on the engine (engine#175) -- so every Atheos on the platform names its
// cookie the same thing. Host-only cookies keep that harmless between
// accounts, but use_strict_mode is what stops a neighbour under a shared
// parent domain from planting a session id of its choosing.
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
if ((\$_SERVER["HTTPS"] ?? "") === "on") ini_set("session.cookie_secure", "1");

// The default is `false`, which reaches date_default_timezone_set() as "" and
// prints a notice on every request.
define("TIMEZONE", "UTC");
define("LANGUAGE", "en");
define("THEME", "dark blue");
define("DEVELOPMENT", false);
define("TITLE", "");
define("BASE_URL", "");

// BASE_PATH is deliberately not defined: Common::\$configDefaults resolves it
// to common.php's own __DIR__, which is right wherever the project is mounted.

// Outside the checkout. /data is ~/.panelalpha/atheos, bind-mounted by the
// compose override, and survives the re-clone a redeploy does (engine#173).
// DATA holds users.json.php -- the password hashes -- the project list, every
// per-user setting and the access log. WORKSPACE holds the customer's code.
define("DATA", "{$data}");
define("WORKSPACE", "{$workspace}");

// checkPath() grants a user with "configure" rights everything under BASE_PATH
// or under WEBROOT, and the project component has a hidden "W3BR00T" shortcut
// that opens the latter. The default is /var/www/html/, which on this image is
// the stock Debian directory and has nothing to do with the account; pointing
// it at the workspace makes the shortcut a no-op rather than a tour of the
// image.
define("WEBROOT", "{$workspace}");

// Upstream's default set, minus `Access-Control-Allow-Origin: *`. A wildcard
// ACAO cannot carry credentials, so it never exposed a signed-in session, but
// there is no reason for an IDE to offer its responses to every origin.
define("HEADERS", serialize(array(
    "Strict-Transport-Security: max-age=31536000; includeSubDomains",
    "X-Frame-Options: SAMEORIGIN",
    "X-Content-Type-Options: nosniff",
    "Referrer-Policy: no-referrer",
    "Feature-Policy: sync-xhr 'self'"
)));

define("UPDATEURL", "https://www.atheos.io/update");
define("MARKETURL", "https://www.atheos.io/market/json");
define("GITHUBAPI", "https://api.github.com/repos/Atheos/Atheos/releases/latest");

PHP;

    file_put_contents($path, $config);
    @chmod($path, 0600);
}

////////////////////////////////////////////////////////////////////// install

// The user file alone, and deliberately not projects.db.php: the shutdown
// handler below writes that one, so an install that died half way through
// would otherwise look complete on the next deploy and the account would never
// get a user at all. It is also the file index.php and process.php test.
$alreadyInstalled = is_file($DATA . '/users.json.php');

if ($alreadyInstalled) {
    writeConfig($configPath, $DATA, $WORKSPACE);
    if (!is_file($stubPath)) {
        file_put_contents($stubPath, $stub);
    }
    fwrite(STDERR, "[panelalpha] Atheos is already installed; config.php rewritten\n");
    exit(0);
}

$credentialsPath = $dataDir . '/admin-credentials';

/**
 * Read once, with a regex rather than parse_ini_file().
 *
 * The file is written for a person to read and its explanatory header contains
 * quotes and URLs; PHP's ini parser does not treat `#` as a comment and chokes
 * on the first one it meets, which cost a deploy.
 */
$password = '';
$username = 'admin';
if (is_file($credentialsPath)) {
    $raw = (string) file_get_contents($credentialsPath);
    if (preg_match('/^ATHEOS_ADMIN_USERNAME=(.*)$/m', $raw, $m) === 1) {
        $username = trim($m[1]);
    }
    if (preg_match('/^ATHEOS_ADMIN_PASSWORD=(.*)$/m', $raw, $m) === 1) {
        $password = trim($m[1]);
    }
}

if ($password === '') {
    // Fail closed, but not into a restart loop. The container restarts
    // `unless-stopped`, so exiting non-zero here would take the site down for
    // good rather than leave it in a safe state. config.php and the
    // installer-guard stub are written instead: Atheos serves a login form
    // against an empty user table, the installer stays shut, and the operator
    // has a legible reason in `docker logs`.
    fwrite(STDERR, "[panelalpha] no usable credentials at {$credentialsPath}; leaving the installer closed and no user created\n");
    writeConfig($configPath, $DATA, $WORKSPACE);
    if (!is_dir(dirname($stubPath))) {
        mkdir(dirname($stubPath), 0755, true);
    }
    file_put_contents($stubPath, $stub);
    exit(0);
}

if (!is_dir($DATA)) {
    mkdir($DATA, 0700, true);
}
if (!is_dir($WORKSPACE)) {
    mkdir($WORKSPACE, 0755, true);
}

// The paths have to be settled before common.php is loaded: Common::initialize()
// only defines a constant it does not already find, and the installer writes
// the user file through DATA.
define('DATA', $DATA);
define('WORKSPACE', $WORKSPACE);

// process.php's own guard reads BASE_PATH . "/data/users.json.php" rather than
// DATA, and the stub shipped there is what keeps the endpoint shut for good.
// Lifted for this one call and written back by the shutdown handler, which runs
// even though Common::send() ends the request with exit().
@unlink($stubPath);
register_shutdown_function(static function () use ($configPath, $DATA, $WORKSPACE, $stubPath, $stub): void {
    if (!is_dir(dirname($stubPath))) {
        mkdir(dirname($stubPath), 0755, true);
    }
    file_put_contents($stubPath, $stub);
    writeConfig($configPath, $DATA, $WORKSPACE);

    // The project the installer just made is written to a file nothing reads.
    // components/install/process.php calls Common::saveJSON("projects.db"),
    // which appends its own ".json" and lands on `projects.db.json.php`, while
    // every reader goes through Common::getKeyStore("projects"), whose Store
    // opens `projects.db.php`. Left alone, an account signs in to an IDE with
    // no project in it. Written back through the store that reads it, so the
    // name and the path are upstream's own.
    if (!class_exists('Common', false)) {
        return;
    }
    // Only once the installer has actually produced a user: a run that died
    // part way through must not leave a projects file behind that makes the
    // next deploy believe there is nothing to do.
    if (!is_file($DATA . '/users.json.php')) {
        return;
    }
    $projects = Common::getKeyStore('projects');
    if ($projects->select('*')) {
        return;
    }
    // Absolute, the way Project::create() stores one: loadState() puts this
    // straight into the session and tests it with is_dir().
    $projects->insert('My Project', $WORKSPACE . '/my-project');
    @unlink($DATA . '/projects.db.json.php');
});

// process.php's one `require_once("../../common.php")` is an explicitly
// relative path, and PHP resolves those against the working directory alone --
// neither the include path nor the including file's directory is consulted for
// a path that starts with "../". So the installer has to be run from its own
// directory. common.php's own `require_once("traits/...")` is not dot-relative
// and resolves against the file that includes it; i18n and
// Common::initialize() work from BASE_PATH; so nothing else minds.
//
// One thing does. Common::version() reads `.version` relative to the working
// directory, so the analytics record written here carries "NaN" as the version
// it was installed at. Analytics::init() replaces the running version from the
// first web request, where the working directory is /app, and the telemetry
// that field belongs to is switched off below.
chdir($base . '/components/install');

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
$host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

$_SERVER['REQUEST_URI'] = '/components/install/process.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
$_SERVER['HTTP_USER_AGENT'] = 'PanelAlpha';
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
$_SERVER['HTTP_HOST'] = $host;

$_POST = [
    'username' => $username,
    'password' => $password,
    'projectName' => 'My Project',
    // Relative, so the installer creates it under WORKSPACE.
    'projectPath' => 'my-project',
    'domain' => '',
    'timezone' => 'UTC',
    'development' => 'false',
    // Atheos posts an install-time telemetry record to atheos.io when this is
    // true, and nags the administrator for an answer when it is the string
    // "UNKNOWN". Anything else means off, and leaves the choice with whoever
    // signs in -- Settings has the switch.
    'analytics' => 'false',
];

require $base . '/components/install/process.php';

// Only reached if process.php's guard declined, which at this point would mean
// the user file appeared between the check above and here.
fwrite(STDERR, "[panelalpha] installer declined; nothing written\n");
exit(0);
