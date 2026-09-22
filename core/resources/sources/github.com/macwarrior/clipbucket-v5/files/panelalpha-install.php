<?php
/*
 * ClipBucket installation, driven from the deploy instead of from a visitor.
 *
 * ClipBucket's web installer is upload/cb_install/, and it is gated on one
 * thing: the existence of upload/files/temp/install.me
 * (cb_install/functions_install.php:2-11, cb_install/ajax.php:11). That file is
 * committed to the repository -- upload/files/temp/.gitignore whitelists it by
 * name -- so a clone arrives with the installer open. hooks/prepare.sh deletes
 * it before anything is built; this file is what then does the installation, in
 * a CLI process on the install stage, before Apache binds.
 *
 * It is not a reimplementation of the installer's UI. The SQL files, their
 * order, the {tbl_prefix}/{dbname} substitution, the version row, the binary
 * paths and the admin seed are exactly what cb_install/ajax.php's `sitesettings`
 * mode does (ajax.php:64-196), and the admin account is finished with
 * upstream's own pass_code() and Migration::updateConfig(), which is what
 * cb_install/modes/finish.php and modes/adminsettings.php do.
 *
 * Modes:
 *   config   write upload/includes/config.php from the engine's DB_* variables.
 *            Pure file writing: it must run before anything bootstraps
 *            ClipBucket, because includes/common.php connects on load.
 *   check    print `installed=0|1` and exit 0. Speaks mysqli directly, so it
 *            works before ClipBucket exists.
 *   schema   import the schema and the reference data, and create the admin
 *            row. Raw mysqli, because there is no application to bootstrap yet.
 *   seed     bootstrap ClipBucket and finish the administrator and the site
 *            settings with upstream's own code.
 *   version  print the schema version and the checkout version, for the upgrade
 *            stage to compare.
 */

if (PHP_SAPI !== 'cli') {
    // Belt and braces. This file sits at the repository root, which is not the
    // document root -- ClipBucket serves from upload/ -- so it is not reachable
    // over HTTP at all; and were the document root ever moved, the generated
    // vhost denies `panelalpha-*` by name.
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden');
}

// Anything PHP has to say goes to stderr. `check` and `version` print shell
// assignments that the caller eval's, and a warning on stdout would be eval'd
// with them. engine#185 leaves the platform with display_errors=1 and no
// php.ini to fix it centrally, so this is not theoretical.
ini_set('display_errors', 'stderr');

$mode = isset($argv[1]) ? $argv[1] : '';
$root = '/app';
$app  = $root . '/upload';

// The table prefix. ClipBucket's own installer offers a field for it and every
// SQL file is written with a {tbl_prefix} placeholder; `cb_` is what upstream
// documents and what every schema dump in cb_install/sql assumes when it is
// read by a human.
$prefix = 'cb_';

function cb_fail($msg)
{
    fwrite(STDERR, "[clipbucket] {$msg}\n");
    exit(1);
}

function cb_log($msg)
{
    fwrite(STDERR, "[clipbucket] {$msg}\n");
}

function cb_env($name, $default = null)
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

/* ----------------------------------------------------------------- config */

if ($mode === 'config') {
    foreach (array('DB_HOST', 'DB_DATABASE', 'DB_USERNAME') as $required) {
        if (cb_env($required) === null) {
            cb_fail("{$required} is not in the environment; is `database: mysql` still in panelalpha.yaml?");
        }
    }

    // Deliberately a file of getenv() calls rather than of values. ClipBucket's
    // own installer writes the credentials in as literals (cb_install/ajax.php
    // :183-195 substituting into cb_install/config.php), which is also the hole
    // that makes that endpoint remote code execution; here there is nothing to
    // substitute, so there is nothing to inject and no second copy of the
    // password on disk. includes/ is outside the document root's reach anyway
    // -- upload/.htaccess bounces `includes/` to /403 -- but the secret has no
    // reason to exist twice, and engine#173 re-clones this directory into a
    // world-readable .env.default on every deploy.
    //
    // includes/common.php reads $DBHOST/$DBNAME/$DBUSER/$DBPASS/$DBPORT and the
    // TABLE_PREFIX constant out of this file's scope, exactly as the installer's
    // template defines them.
    $config = <<<'PHP'
<?php
/* Written by PanelAlpha on every deploy. Edits are lost on the next one.
 *
 * The values are read from the container's environment rather than written
 * here: the engine hands this container DB_HOST, DB_PORT, DB_DATABASE,
 * DB_USERNAME and DB_PASSWORD for the database it provisioned on the account's
 * own MySQL server, and the password has no reason to be on disk as well.
 */
$DBHOST = getenv('DB_HOST');
$DBNAME = getenv('DB_DATABASE');
$DBUSER = getenv('DB_USERNAME');
$DBPASS = (string)getenv('DB_PASSWORD');
$DBPORT = getenv('DB_PORT') ?: '3306';
define('TABLE_PREFIX', 'cb_');
PHP;

    $target = $app . '/includes/config.php';
    if (file_put_contents($target, $config . "\n") === false) {
        cb_fail("cannot write {$target}");
    }
    chmod($target, 0644);

    cb_log('wrote upload/includes/config.php for ' . cb_env('DB_DATABASE') . '@' . cb_env('DB_HOST'));
    exit(0);
}

/* --------------------------------------------------------------- database */

function cb_connect()
{
    // PHP 8.1 made mysqli throw by default, and `@` does not suppress an
    // exception. Every probe below is asked before ClipBucket is installed,
    // when the tables genuinely do not exist, so a missing table has to come
    // back as false rather than as a fatal whose message is the only thing on
    // stdout.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = @new mysqli(
        cb_env('DB_HOST'),
        cb_env('DB_USERNAME'),
        cb_env('DB_PASSWORD'),
        cb_env('DB_DATABASE'),
        (int) cb_env('DB_PORT', '3306')
    );
    if ($db->connect_errno) {
        cb_fail('cannot reach the database: ' . $db->connect_error);
    }
    $db->query('SET NAMES "utf8mb4"');
    return $db;
}

function cb_schema_version($db, $prefix)
{
    $rs = @$db->query("SELECT `version`, `revision` FROM `{$prefix}version` WHERE id = 1 LIMIT 1");
    if ($rs && $rs->num_rows > 0) {
        $row = $rs->fetch_assoc();
        return $row['version'] . '.' . $row['revision'];
    }
    return '';
}

/* ------------------------------------------------------------------ check */

if ($mode === 'check') {
    $db      = cb_connect();
    $version = cb_schema_version($db, $prefix);

    // An administrator with a password as well as a version row: an install
    // that built the schema and then died would otherwise look finished and
    // leave an application nobody can log into. add_admin.sql inserts userid 1
    // with `password` = '' and the `seed` phase is what fills it in, so the
    // empty string is exactly the half-done state worth catching.
    $admin = 0;
    $rs = @$db->query("SELECT userid FROM `{$prefix}users` WHERE userid = 1 AND password <> '' LIMIT 1");
    if ($rs && $rs->num_rows > 0) {
        $admin = 1;
    }

    $installed = ($version !== '' && $admin === 1) ? 1 : 0;

    // Three answers, not one, because the two halves of an installation fail
    // separately and the schema phase is not re-runnable: cb_install's DDL is
    // plain `CREATE TABLE`, so a second pass over a database that already has
    // the tables stops on "Table 'cb_action_log' already exists". The engine
    // retries a failed install stage, so an installation that imported the
    // schema and then died in the seed has to be able to resume at the seed.
    //
    // One assignment per line: the caller filters this through a grep anchored
    // at both ends, so a second assignment on the same line would be dropped.
    echo "installed={$installed}\n";
    echo "schema=" . ($version !== '' ? 1 : 0) . "\n";
    echo "admin={$admin}\n";
    exit(0);
}

/* ----------------------------------------------------------------- schema */

/**
 * cb_install/functions_install.php:59-91, minus the die(json_encode(...)) that
 * belongs to an ajax endpoint. Statements are separated by a line whose last
 * character is a semicolon -- which is how ClipBucket's own dumps are written
 * and why they cannot simply be explode()d on ';'.
 */
function cb_execute_sql_file($db, $path, $prefix, $dbname)
{
    $lines = @file($path);
    if ($lines === false) {
        cb_fail("cannot read {$path}");
    }

    $statement = '';
    foreach ($lines as $line) {
        $statement .= $line;
        if (substr(rtrim($line), -1, 1) !== ';') {
            continue;
        }
        $statement = str_replace(array('{tbl_prefix}', '{dbname}'), array($prefix, $dbname), $statement);
        if (!$db->query($statement)) {
            cb_fail(
                basename($path) . ': ' . $db->error
                . ' -- ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 200)
            );
        }
        $statement = '';
    }
    return true;
}

if ($mode === 'schema') {
    $db     = cb_connect();
    $dbname = cb_env('DB_DATABASE');
    $sql    = $app . '/cb_install/sql/';

    // Exactly cb_install/ajax.php's `$files` list and its order (ajax.php:68-83),
    // then the two steps that follow it (`add_categories`, `add_admin`). The
    // reset_db.sql entry is not here: ajax.php only ever runs it in dev mode,
    // and a deploy has no business dropping an account's tables.
    $files = array(
        'structure.sql',
        'table_version.sql',
        'configs.sql',
        'languages.sql',
        'language_ENG.sql',
        'language_FRA.sql',
        'language_DEU.sql',
        'language_POR.sql',
        'language_ESP.sql',
        'ads_placements.sql',
        'countries.sql',
        'email_templates.sql',
        'pages.sql',
        'user_levels.sql',
        // categories.sql is not here and its absence is deliberate: the file is
        // zero bytes in this checkout and the `add_categories` step that would
        // run it is unreachable in ajax.php's own step chain, which goes
        // user_levels -> add_admin. The video, user, group and collection
        // categories -- including the `Gurus` row add_admin.sql then joins a
        // user to -- are inserted by configs.sql, which is above.
        'add_admin.sql',
        'add_anonymous_user.sql',
    );

    foreach ($files as $file) {
        // ajax.php's list names language_DEU.sql and language_ESP.sql, which
        // this checkout does not ship -- its own step loop would have imported
        // nothing and moved on, and so does this.
        if (!is_file($sql . $file)) {
            cb_log("skipping {$file} (not in this checkout)");
            continue;
        }
        cb_execute_sql_file($db, $sql . $file, $prefix, $dbname);
        cb_log("imported {$file}");
    }

    // The version row, from the checkout's own changelog -- ajax.php:158-166.
    // Written after table_version.sql created the table and before anything
    // reads Update::getCurrentDBVersion(), because every
    // IsCurrentDBVersionIsHigherOrEqualTo() call in the application is gated on
    // it and a missing row makes the whole of ClipBucket behave like a 5.0
    // install.
    $latest = @json_decode(@file_get_contents($app . '/changelog/latest.json'), true);
    if (!is_array($latest) || empty($latest['stable'])) {
        cb_fail('changelog/latest.json is missing or has no `stable` key');
    }
    $changelog = @json_decode(@file_get_contents($app . '/changelog/' . $latest['stable'] . '.json'), true);
    if (!is_array($changelog) || empty($changelog['version'])) {
        cb_fail('changelog/' . $latest['stable'] . '.json is missing or has no `version` key');
    }
    $version  = $db->real_escape_string($changelog['version']);
    $revision = (int) $changelog['revision'];
    if (!$db->query(
        "INSERT INTO `{$prefix}version` SET version = '{$version}', revision = {$revision}, id = 1"
        . " ON DUPLICATE KEY UPDATE version = '{$version}', revision = {$revision}"
    )) {
        cb_fail('cannot write the version row: ' . $db->error);
    }
    cb_log("version {$version} revision {$revision}");

    cb_log('schema imported');
    exit(0);
}

/* ------------------------------------------------- binaries and settings */

/**
 * Where the tools are. ClipBucket resolves every external binary through
 * System::get_binaries() (includes/classes/system.class.php:547-620), which
 * reads a config row first and only falls back to `which`. The shared PHP base
 * image has none of ffmpeg, ffprobe or mediainfo, so `which` would find
 * nothing; hooks/prepare.sh puts static builds on the account's own data
 * directory and the compose override mounts it at /data.
 *
 * @return array<string,string> config row name => absolute path
 */
function cb_binaries()
{
    $paths = array(
        'ffmpegpath'   => '/data/bin/ffmpeg',
        'ffprobe_path' => '/data/bin/ffprobe',
        'media_info'   => '/data/bin/mediainfo',
        // PHP_BINARY is the CLI binary this process is running as, which is the
        // one every backgrounded conversion will be started with.
        'php_path'     => PHP_BINARY,
        // git is in the base image (php-base.stub installs it). ClipBucket uses
        // it only for its own in-place "update from git" feature, which a
        // hosting account does not drive -- the engine redeploys instead.
        'git_path'     => '/usr/bin/git',
    );

    $out = array();
    foreach ($paths as $name => $path) {
        if ($name === 'php_path' || is_file($path)) {
            $out[$name] = $path;
        }
    }
    return $out;
}

/* --------------------------------------------------------------- bootstrap */

// What Apache would have put here. ClipBucket reads SCRIPT_NAME to work out the
// subdirectory it is mounted in when no base_url is configured yet
// (Network::get_server_url(), includes/classes/network.class.php:297-322), and
// HTTP_HOST for its cookie domain.
$url   = cb_env('APP_URL', cb_env('URL', ''));
$host  = parse_url($url, PHP_URL_HOST) ?: 'localhost';
$https = stripos($url, 'https://') === 0;

$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST']       = $host;
$_SERVER['SERVER_NAME']     = $host;
$_SERVER['SERVER_PORT']     = $https ? '443' : '80';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT']   = $app;
$_SERVER['SCRIPT_FILENAME'] = $app . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['HTTP_USER_AGENT'] = 'PanelAlpha deploy';
if ($https) {
    $_SERVER['HTTPS'] = 'on';
}

if (!is_file($app . '/includes/config.php')) {
    cb_fail('upload/includes/config.php does not exist yet -- panelalpha-setup.sh writes it');
}

// ClipBucket bootstraps from includes/config.inc.php, and it does so happily
// from the CLI: actions/video_convert.php -- upstream's own conversion worker --
// is a CLI script whose second line includes exactly this file. $in_bg_cron is
// what tells config.inc.php not to apply the "site closed" and "login required"
// redirects to a process that is not a request.
define('THIS_PAGE', 'panelalpha_install');
$in_bg_cron = true;
chdir($app);

if ($mode === 'version' || $mode === 'seed') {
    require_once $app . '/includes/config.inc.php';
    // config.inc.php does not pull these in -- ClipBucket has no autoloader for
    // its own classes, only Composer's for vendor/, and each entry point
    // requires what it needs. cb_install/index.php:8-11 requires exactly these.
    require_once DirPath::get('classes') . 'update.class.php';
    require_once DirPath::get('classes') . 'migration' . DIRECTORY_SEPARATOR . 'migration.class.php';
}

/* ---------------------------------------------------------------- version */

if ($mode === 'version') {
    $update = Update::getInstance();
    echo 'schema=' . $update->getCurrentDBVersion() . '.' . $update->getCurrentDBRevision() . "\n";
    echo 'code=' . $update->getCurrentCoreVersion() . '.' . $update->getCurrentCoreRevision() . "\n";
    exit(0);
}

/* ------------------------------------------------------------------- seed */

if ($mode !== 'seed') {
    cb_fail("unknown mode '{$mode}' (config|check|schema|seed|version)");
}

$credentials = '/data/admin-credentials';
if (!is_file($credentials)) {
    cb_fail("{$credentials} is missing; hooks/prepare.sh generates it");
}
$values = array();
foreach (file($credentials) as $line) {
    if (preg_match('/^CLIPBUCKET_ADMIN_(USERNAME|PASSWORD)=(.*)$/', trim($line), $m)) {
        $values[strtolower($m[1])] = $m[2];
    }
}
if (empty($values['username']) || empty($values['password'])) {
    cb_fail("{$credentials} does not carry CLIPBUCKET_ADMIN_USERNAME and _PASSWORD");
}
$username = $values['username'];
$password = $values['password'];
$email    = cb_env('CLIPBUCKET_ADMIN_EMAIL', 'admin@' . $host);

/* 1. The external tools, as config rows.
 *
 * cb_install/ajax.php:129-155 does the same UPDATE for the same five rows after
 * configs.sql has been imported -- from `which` or from whatever the precheck
 * form was told. Here the paths are known.
 */
foreach (cb_binaries() as $name => $path) {
    Migration::updateConfig($name, $path);
    cb_log("{$name} = {$path}");
}

/* 2. The site, as cb_install/modes/adminsettings.php:2-8 writes it. */
$base_url = rtrim($url, '/');
if ($base_url === '') {
    cb_fail('APP_URL is not in the environment; ClipBucket needs an absolute base_url');
}
Migration::updateConfig('base_url', $base_url);
Migration::updateConfig('site_title', cb_env('CLIPBUCKET_SITE_TITLE', 'ClipBucket'));
Migration::updateConfig('site_slogan', cb_env('CLIPBUCKET_SITE_SLOGAN', 'A way to broadcast yourself'));
// The container's clock is UTC and ClipBucket warns on every admin page when
// the web and CLI SAPIs disagree about the time.
Migration::updateConfig('timezone', 'UTC');
// Upstream's own installer offers this as an opt-in checkbox, unchecked. A
// hosting platform does not opt an account into telemetry on its behalf.
Migration::updateConfig('enable_anonymous_stats', 'no');
// Written by adminsettings.php so that the admin area's "your vhost is out of
// date" banner is quiet. This deployment is Apache, not nginx, so the banner
// would be advice about a file that does not exist here.
Migration::updateConfig('nginx_vhost_version', Update::getInstance()->getCurrentCoreVersion());
Migration::updateConfig('nginx_vhost_revision', Update::getInstance()->getCurrentCoreRevision());

/* 3. The administrator, as cb_install/modes/finish.php:2-10 writes it.
 *
 * pass_code() is upstream's own hashing (includes/functions.php:21-25) and
 * reads the per-install password_salt that configs.sql generated with
 * SHA2(NOW(), RAND(), UUID()) -- so the hash is only meaningful inside this
 * account's own database. It is sha512 of password+userid+salt with no
 * iterations, which is upstream's choice and not something a recipe can change
 * without forking the application.
 */
$db = Clipbucket_db::getInstance();
$db->update(
    tbl('users'),
    array('username', 'password', 'email', 'doj', 'num_visits', 'ip', 'signup_ip'),
    array($username, pass_code($password, 1), $email, now(), 0, '127.0.0.1', '127.0.0.1'),
    'userid = 1'
);

cb_log("administrator '{$username}' <{$email}> created");
cb_log('seed finished');
exit(0);
