<?php
/*
 * ZenTao installation, driven from the deploy instead of from a visitor.
 *
 * ZenTao's web installer is `www/install.php`, and a git checkout does not have
 * one: `.gitignore` lists `www/install.php` and `www/upgrade.php`, the tree
 * ships `www/install.php.tmp` and `www/upgrade.php.tmp`, and the Makefile
 * renames them only when it builds a release tarball (Makefile:85-86). So
 * unlike CouchCMS, OpenEMR or Omeka S there is no unauthenticated wizard to
 * race -- provided nothing in the deploy creates one, which nothing here does.
 *
 * What is still needed is the installation itself, and it runs here: in a CLI
 * process from the install stage, before Apache binds, with a password
 * generated per account. The framework is bootstrapped exactly as
 * www/install.php.tmp bootstraps it -- `router::createApp('pms', '/app',
 * 'router', 'installing')` -- and the work is done by upstream's own
 * installModel: replaceContantsInSQL(), appendMySQLTableOptions(), grantPriv(),
 * updateLang(), execPostInstallSQL(), enableCache(), updateDbSeq(), plus the
 * settings writes install/control.php::step5() makes. Nothing here
 * reimplements the schema, the privilege seed or the password hashing.
 *
 * The one piece that is ours is the loop that reads db/zentao.sql into
 * statements, because upstream's getInstallSQLs() is private to installZen and
 * reads the list out of the installer's own HTTP session.
 *
 * Modes:
 *   config   write config/my.php from the database the engine provisioned.
 *            Pure file writing: it must run before anything bootstraps the
 *            framework, because router's constructor connects to the database.
 *   check    print `installed=0|1` and exit 0. Speaks mysqli directly, so it
 *            works before ZenTao exists.
 *   schema   create the database schema. Runs with $config->installed false.
 *   seed     create the company, the administrator and the settings.
 *   version  print the schema version and the checkout version, for the
 *            upgrade stage to compare.
 */

if (PHP_SAPI !== 'cli') {
    // Belt and braces. This file sits at the repository root, which is not the
    // document root -- ZenTao serves from www/ -- so it is not reachable over
    // HTTP at all; and were the document root ever moved, the generated vhost
    // denies `panelalpha-*` by name.
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden');
}

// Anything PHP has to say goes to stderr. `check` and `version` print shell
// assignments that the caller eval's, and a warning on stdout would be eval'd
// with them -- a message containing an apostrophe becomes an unterminated
// string in the calling shell rather than a readable failure. #185 leaves the
// platform with display_errors=1 and no php.ini to fix it centrally, so this
// is not theoretical.
ini_set('display_errors', 'stderr');

$mode = isset($argv[1]) ? $argv[1] : '';
$root = '/app';

function zt_fail($msg)
{
    fwrite(STDERR, "[zentao] {$msg}\n");
    exit(1);
}

function zt_log($msg)
{
    fwrite(STDERR, "[zentao] {$msg}\n");
}

function zt_env($name, $default = null)
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
        if (zt_env($required) === null) {
            zt_fail("{$required} is not in the environment; is `database: mysql` still in panelalpha.yaml?");
        }
    }

    // Deliberately a file of getenv() calls rather than of values: the database
    // password is handed to the container in its environment already, and
    // writing it into the checkout as well would put it in a file that #173
    // then re-clones over -- and that a backup, a `git status` or a stray
    // archive could carry off. config/ is outside the document root (www/), so
    // this file is not web-readable either way, but there is no reason for the
    // secret to exist twice.
    //
    // config/config.php includes config/my.php last (config.php:253), after its
    // own IN_CONTAINER env block and after config/db.php, so everything set
    // here wins.
    //
    // $config->inContainer is set here rather than passed as IN_CONTAINER in
    // the environment on purpose. Setting the environment variable would make
    // config.php:225-245 take the database configuration from ZT_* variables
    // that do not exist, clobbering it with false before this file is reached.
    // Setting it afterwards gets the two behaviours that matter without that:
    //   - router::checkInstalled() (router.class.php:3681) stops trusting the
    //     `installed` flag alone and also requires the version row in the
    //     database, so "installed" becomes a property of the thing that
    //     survives a redeploy rather than of a file in the checkout;
    //   - commonModel::checkSafeFile() (common/model.php:1168) returns early,
    //     disabling upstream's "safe mode", which otherwise nags on every page
    //     until somebody creates www/data/ok.txt on the filesystem by hand.
    $my = <<<'PHP'
<?php
/* Written by PanelAlpha on every deploy. Edits are lost on the next one. */

/* True except while this recipe's own installer is running.
 *
 * router's constructor loads ZenTao's settings out of zt_config as soon as
 * $config->installed is true (framework/router.class.php:~300-333), and during
 * the install those tables do not exist yet -- the bootstrap dies with
 * "Base table or view not found: zt_config" before it can create them.
 * www/install.php.tmp never hits this because a fresh checkout has no my.php at
 * all and so is never "installed"; this file has to say the same thing for the
 * length of one process. panelalpha-install.php putenv()s this immediately
 * before createApp(). */
$config->installed   = getenv('PA_ZENTAO_INSTALLING') !== '1';
$config->inContainer = true;
$config->debug       = 0;
$config->timezone    = 'UTC';

$config->db->driver   = 'mysql';
$config->db->host     = getenv('DB_HOST');
$config->db->port     = getenv('DB_PORT') ?: '3306';
$config->db->name     = getenv('DB_DATABASE');
$config->db->user     = getenv('DB_USERNAME');
$config->db->password = getenv('DB_PASSWORD');
$config->db->encoding = 'UTF8';
$config->db->prefix   = 'zt_';

/* ZenTao is served from the account's own domain root: www/ is the document
   root, so index.php is at '/'. */
$config->webRoot = '/';

/* The checkout ships zh-cn as the default. */
$config->default->lang = 'en';
PHP;

    $target = $root . '/config/my.php';
    if (file_put_contents($target, $my . "\n") === false) {
        zt_fail("cannot write {$target}");
    }
    chmod($target, 0644);

    zt_log('wrote config/my.php for ' . zt_env('DB_DATABASE') . '@' . zt_env('DB_HOST'));
    exit(0);
}

/* ------------------------------------------------------------------ check */

function zt_connect()
{
    // PHP 8.1 made mysqli throw by default, and `@` does not suppress an
    // exception. The version query below is asked before ZenTao is installed,
    // when zt_config genuinely does not exist, so a missing table has to come
    // back as false rather than as a fatal whose message is the only thing on
    // stdout.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db = @new mysqli(
        zt_env('DB_HOST'),
        zt_env('DB_USERNAME'),
        zt_env('DB_PASSWORD'),
        zt_env('DB_DATABASE'),
        (int) zt_env('DB_PORT', '3306')
    );
    if ($db->connect_errno) {
        zt_fail('cannot reach the database: ' . $db->connect_error);
    }
    return $db;
}

function zt_schema_version($db)
{
    $rs = @$db->query(
        "SELECT `value` FROM `zt_config`"
        . " WHERE `owner`='system' AND `module`='common' AND `section`='global' AND `key`='version'"
        . " LIMIT 1"
    );
    if ($rs && $rs->num_rows > 0) {
        $row = $rs->fetch_row();
        return (string) $row[0];
    }
    return '';
}

if ($mode === 'check') {
    $db      = zt_connect();
    $version = zt_schema_version($db);

    // A user row as well as a version row: an install that created the schema
    // and then died before grantPriv() would otherwise look finished and leave
    // an application nobody can log into.
    $admins = 0;
    $rs = @$db->query("SELECT COUNT(*) FROM `zt_user` WHERE `deleted`='0'");
    if ($rs && $rs->num_rows > 0) {
        $row    = $rs->fetch_row();
        $admins = (int) $row[0];
    }

    $installed = ($version !== '' && $admins > 0) ? 1 : 0;

    // One assignment per line: the caller filters this through a grep anchored
    // at both ends, so a second assignment on the same line would be dropped.
    echo "installed={$installed}\n";
    exit(0);
}

/* --------------------------------------------------------------- bootstrap */

// What Apache would have put here. router::setWwwRoot() takes the directory of
// SCRIPT_FILENAME as the web root (router.class.php:781), and dataRoot is
// derived from it (router.class.php:805), so this is what decides that
// attachments land in /app/www/data rather than somewhere under /app.
$url   = zt_env('APP_URL', zt_env('URL', ''));
$host  = parse_url($url, PHP_URL_HOST) ?: 'localhost';
$https = stripos($url, 'https://') === 0;

$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST']       = $host;
$_SERVER['SERVER_NAME']     = $host;
$_SERVER['SERVER_PORT']     = $https ? '443' : '80';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT']   = $root . '/www';
$_SERVER['SCRIPT_FILENAME'] = $root . '/www/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['HTTP_USER_AGENT'] = 'PanelAlpha deploy';
if ($https) {
    $_SERVER['HTTPS'] = 'on';
}

if (!is_file($root . '/config/my.php')) {
    zt_fail('config/my.php does not exist yet -- panelalpha-setup.sh writes it');
}

// The framework resolves its own paths from __FILE__ and expects to be entered
// from www/, the way index.php is.
chdir($root . '/www');

include $root . '/framework/router.class.php';
include $root . '/framework/control.class.php';
include $root . '/framework/model.class.php';
include $root . '/framework/helper.class.php';


// PA_ZENTAO_INSTALLING is read by the config/my.php this script writes, and is
// what keeps $config->installed false for the length of the `schema` process:
// the router's constructor reads ZenTao's settings out of zt_config the moment
// that flag is true, and on a first deploy zt_config is exactly what we are
// about to create. `seed` and `version` deliberately do not set it -- by then
// the tables exist, and both want a real connection and a real DAO, which
// router::connectDB() refuses to build while `installed` is false
// (framework/base/router.class.php:3060).
//
// This is why installation is two processes rather than one. ZenTao's own web
// installer has the same split for the same reason: showTableProgress and
// ajaxCreateTable build the schema in requests that are not yet "installed",
// and step5 seeds the company and the administrator in a later one that is.
if ($mode === 'schema') {
    putenv('PA_ZENTAO_INSTALLING=1');
}

// 'installing' is the fourth argument www/install.php.tmp passes. It is what
// stops the router insisting the application already exists while we are
// building it.
try {
    $app = router::createApp('pms', $root, 'router', 'installing');
} catch (Throwable $e) {
    zt_fail('cannot start ZenTao: ' . $e->getMessage());
}

global $config, $lang;

/* ---------------------------------------------------------------- version */

if ($mode === 'version') {
    $db = zt_connect();
    echo 'schema=' . zt_schema_version($db) . "\n";
    echo 'code=' . $config->version . "\n";
    exit(0);
}

if ($mode !== 'schema' && $mode !== 'seed') {
    zt_fail("unknown mode '{$mode}' (config|check|version|schema|seed)");
}

$install = $app->loadTarget('install');
if (!$install) {
    zt_fail("could not load ZenTao's install model");
}

/* ----------------------------------------------------------------- schema */

if ($mode === 'schema') {
    // router::connectDB() returned early (installed is false), so there is no
    // $app->dbh and no DAO yet. installModel::connectDB() is what ZenTao's own
    // installer uses at exactly this point (install/control.php::ajaxCreateTable,
    // which does the same connectDB()/useDB() pair): a bare PDO handle that does
    // not need the application to exist.
    $dbh = $install->connectDB();
    if (!is_object($dbh)) {
        zt_fail('cannot reach the database: ' . $dbh);
    }
    $dbh->useDB($config->db->name);

    // db/zentao.sql is the whole schema -- 645 KB, every table -- and
    // db/dbviews.sql adds the views on top, in that order so the tables exist
    // first. This loop is upstream's getInstallSQLs() (install/zen.php:516)
    // minus the parts that read the installer's HTTP session: split on ';',
    // drop comments, and put each statement through the model's own constant
    // substitution (which is what turns the literal `zt_` prefixes into
    // $config->db->prefix and expands __DATABASE__) and MySQL table options.
    $dbh->exec('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO"');

    $executed = 0;
    foreach (array('zentao.sql', 'dbviews.sql') as $file) {
        $path = $root . '/db/' . $file;
        if (!is_file($path)) {
            zt_fail("db/{$file} is missing from the checkout");
        }

        foreach (explode(';', file_get_contents($path)) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0) {
                continue;
            }

            $statement = $install->replaceContantsInSQL($statement);
            $statement = $install->appendMySQLTableOptions($statement);
            if (trim($statement) === '') {
                continue;
            }

            try {
                $dbh->exec($statement);
                $executed++;
            } catch (Throwable $e) {
                // Every CREATE in these files is IF NOT EXISTS, so a rerun is
                // quiet; anything else that fails is a real failure and the
                // application would be half-built.
                zt_fail(
                    "failed on a statement from db/{$file}: " . $e->getMessage()
                    . ' -- ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 160)
                );
            }
        }
    }

    zt_log("schema: {$executed} statements executed");
    exit(0);
}

/* ------------------------------------------------------------------- seed */

// Reached only in `seed`. $config->installed is true here, so the router has
// already connected and loaded the DAO, and the models below have a working
// $this->dao.

$pwdFile = $root . '/.panelalpha-admin-password';
if (!is_file($pwdFile)) {
    zt_fail("{$pwdFile} is missing; hooks/prepare.sh generates it");
}
$password = trim(file_get_contents($pwdFile));
if (strlen($password) < 6) {
    zt_fail('the generated admin password is too short for ZenTao (grantPriv wants 6+)');
}

$account = zt_env('ZENTAO_ADMIN_USER', 'admin');
$company = zt_env('ZENTAO_COMPANY', 'ZenTao');

foreach (array('install', 'user', 'company', 'upgrade', 'custom') as $module) {
    $app->loadLang($module);
}
$app->loadConfig('admin');

/* 1. The company and the first administrator.
 *
 * Upstream's own grantPriv(): it validates the account against
 * validater::checkAccount(), rejects a password under 6 characters, one that
 * scores below 1 on computePasswordStrength(), or one in $config->safe->weak,
 * inserts the company row with this account as its admin, and inserts the user
 * with ZenTao's own hashing. (That hashing is bare md5 -- upstream's choice,
 * not something this recipe can change without modifying the source and
 * becoming a derived work under ZPL section 6.)
 */
$install->updateDbSeq();

$data           = new stdclass();
$data->company  = $company;
$data->account  = $account;
$data->password = $password;

if (!$install->grantPriv($data)) {
    zt_fail('grantPriv refused the administrator: ' . json_encode(dao::getError()));
}
zt_log("created company '{$company}' and super-admin '{$account}'");

/* 2. Everything install/control.php::step5() does after grantPriv. */
$setting = $app->loadTarget('setting');
if (!$setting) {
    zt_fail("could not load ZenTao's setting model");
}

// 'ALM' is the full mode -- Program, Product, Project and Execution. 'light'
// is the cut-down one that hides Program entirely (common/lang/menu.php:86),
// and a hosting default should not silently remove half the product.
$setting->setItem('system.common.global.mode', 'ALM');
$setting->setItem('system.common.global.flow', 'full');
$setting->setItem('system.common.safe.mode', '1');
$setting->setItem('system.common.safe.changeWeak', '1');
$setting->setItem('system.common.global.cron', '1');
$setting->setItem('system.common.userview.relatedTablesUpdateTime', time());

$install->updateLang();

// Ordered last of the settings because getInstalledVersion() reads exactly this
// row, and checkInstalled() treats its absence as "not installed": until it is
// written, a half-finished install still looks unfinished rather than broken.
$setting->updateVersion($config->version);

// The remainder are upstream's finishing touches. None of them decides whether
// ZenTao works, and each one can fail on an open-edition checkout without that
// meaning the install failed -- execPostInstallSQL() only has work to do on
// Dameng and PostgreSQL, and processDataset()/initAllWorkflowGroup() are
// explicitly skipped for `edition == 'open'` in step5. So they are attempted
// and logged rather than fatal, and the deploy is not failed by a cosmetic step
// after the application is already usable.
foreach (
    array(
        'execPostInstallSQL' => static function () use ($install) { $install->execPostInstallSQL(); },
        'importBIData'       => static function () use ($install) { $install->importBIData(); },
        'enableCache'        => static function () use ($install) { $install->enableCache(); },
        'setSN'              => static function () use ($setting) { $setting->setSN(); },
        'metricDate'         => static function () use ($app) {
            $metric = $app->loadTarget('metric');
            if ($metric) {
                $metric->updateMetricDate();
            }
        },
    ) as $name => $step
) {
    try {
        $step();
    } catch (Throwable $e) {
        zt_log("optional step {$name} failed (continuing): " . $e->getMessage());
    }
}

zt_log('seed finished');
exit(0);
