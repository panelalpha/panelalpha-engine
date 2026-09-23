<?php
/**
 * PanelAlpha install/upgrade for b1gMail. Runs from the generated entrypoint in
 * the app container, as the account user, before Apache starts listening.
 *
 * b1gMail has no installer CLI - setup/index.php *is* the installer, and it is
 * first-visitor-wins against a database that is empty until somebody runs it.
 * Driving it from here is what closes that window: by the time the port opens
 * the wizard has run and src/setup/ is gone.
 *
 * Everything here is top level on purpose. b1gMail's init.inc.php, its
 * common.inc.php and setup/index.php all keep state in globals and read it
 * back through `global` declarations; included from inside a function their
 * variables would be function-locals and the `global` reads would see nothing.
 */

// PHP 8.1 made mysqli throw on error. b1gMail's setup and its DB layer both
// test return values instead, so the first failed query would be an uncaught
// exception rather than the warning the code handles.
mysqli_report(MYSQLI_REPORT_OFF);

define('PA_APP', '/app/src');
define('PA_DATA_DIR', '/var/lib/b1gmail/data/');
define('PA_PREFIX', 'bm60_');

function pa_say(string $msg): void
{
    fwrite(STDERR, '[panelalpha] b1gmail: ' . $msg . "\n");
}

function pa_fail(string $msg): void
{
    fwrite(STDERR, '[panelalpha] b1gmail: FAILED - ' . $msg . "\n");
    exit(1);
}

function pa_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
    }
    @rmdir($dir);
}

$paHost = getenv('DB_HOST') ?: 'localhost';
$paUser = (string) getenv('DB_USERNAME');
$paPass = (string) getenv('DB_PASSWORD');
$paName = (string) getenv('DB_DATABASE');
$paPort = (int) (getenv('DB_PORT') ?: 3306);
if ($paName === '') {
    pa_fail('DB_DATABASE is empty - the manifest asks for `database: mysql`, so the engine should have provisioned one.');
}

// The account's MySQL server is a container away and a first deploy can beat it.
$paConn = null;
for ($paTry = 1; $paTry <= 30; ++$paTry) {
    $paConn = @mysqli_connect($paHost, $paUser, $paPass, $paName, $paPort);
    if ($paConn) {
        break;
    }
    sleep(2);
}
if (!$paConn) {
    pa_fail('cannot reach MySQL at ' . $paHost . ':' . $paPort . ' - ' . mysqli_connect_error());
}
mysqli_set_charset($paConn, 'utf8mb4');
@mysqli_query($paConn, "SET SESSION sql_mode=''");

// The config file shipped in files/ reads the environment and holds no secret.
// The setup wizard overwrites it with one that has the password inlined, so
// keep a copy now and put it back at the end.
$paConfig = @file_get_contents(PA_APP . '/serverlib/config.inc.php');

$paInstalled = false;
if ($paRes = @mysqli_query($paConn, 'SELECT COUNT(*) FROM `' . PA_PREFIX . 'prefs`')) {
    [$paRows] = mysqli_fetch_row($paRes);
    $paInstalled = ((int) $paRows) > 0;
    mysqli_free_result($paRes);
}

// The database is the only honest answer to "has this been installed" -
// ~/project is emptied on every deploy, so nothing on disk can say.
if ($paInstalled) {
    pa_say('database already holds a b1gMail installation; syncing schema only');

    // What tools/db_sync.php does, and what the ACP's Tools -> Optimize ->
    // "check structure" does: load b1gMail, load the shipped structure, sync.
    chdir(PA_APP);
    require PA_APP . '/serverlib/init.inc.php';
    require PA_APP . '/serverlib/database.struct.php';
    ob_start();
    SyncDBStruct(json_decode($databaseStructure, JSON_OBJECT_AS_ARRAY));
    $paOut = trim(strip_tags((string) ob_get_clean()));
    if ($paOut !== '') {
        pa_say('schema sync: ' . substr($paOut, 0, 400));
    }
} else {
    pa_say('fresh database; running b1gMail setup');

    $paDomain = getenv('SERVERNAME') ?: (parse_url((string) getenv('URL'), PHP_URL_HOST) ?: 'localhost');
    $paUrl = rtrim((string) (getenv('URL') ?: 'https://' . $paDomain), '/') . '/';
    $paAdminPw = getenv('B1GMAIL_ADMIN_PASSWORD');
    if (!$paAdminPw) {
        pa_fail('B1GMAIL_ADMIN_PASSWORD is not set - the prepare hook writes it into ~/.panelalpha/b1gmail/b1gmail.env and the compose override passes that in as a second env_file.');
    }

    @unlink(PA_APP . '/setup/lock');

    // Straight to STEP_INSTALL (8). The steps before it are the form and its
    // validation; STEP_CHECK_EMAIL is the one that insists on a POP3 box that
    // answers, and there is no mailbox to name at deploy time. The catchall is
    // an ACP setting the account holder fills in later.
    $_GET = $_POST = $_REQUEST = [
        'step' => 8,
        'lng' => 'english',
        'mysql_host' => $paHost,
        'mysql_user' => $paUser,
        'mysql_pass' => $paPass,
        'mysql_db' => $paName,
        // Not "public", which is the wizard's own default: that leaves open
        // self-registration on a webmail server nobody has configured yet.
        'setup_mode' => 'private',
        // b1gMail's own default (setup/index.php:339 is the checked radio).
        // The gateway pulls from a catchall mailbox over POP3. The alternative,
        // "pipe", makes the app the destination MTA for its domain, which a
        // tenant application on shared hosting can never be.
        'receive_method' => 'pop3',
        'pop3_host' => '',
        'pop3_user' => '',
        'pop3_pass' => '',
        'send_method' => 'php',
        'smtp_host' => '',
        'sendmail_path' => '',
        'adminpw' => $paAdminPw,
        'domains' => $paDomain,
        'url' => $paUrl,
    ];

    // setup/index.php is a web page; hand it the $_SERVER keys it reads.
    // SCRIPT_FILENAME is the load-bearing one: the wizard derives
    // prefs.datafolder and prefs.selffolder from it with a regex.
    $_SERVER['HTTP_HOST'] = $paDomain;
    $_SERVER['SERVER_NAME'] = $paDomain;
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
    $_SERVER['REQUEST_URI'] = '/setup/index.php';
    $_SERVER['SCRIPT_FILENAME'] = PA_APP . '/setup/index.php';
    $_SERVER['SCRIPT_NAME'] = '/setup/index.php';
    $_SERVER['PHP_SELF'] = '/setup/index.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    chdir(PA_APP . '/setup');
    ob_start();
    require PA_APP . '/setup/index.php';
    $paHtml = (string) ob_get_clean();

    if (stripos($paHtml, 'Lockfile detected') !== false) {
        pa_fail('setup refused to run: src/setup/lock is still in place');
    }
    if (preg_match('#<textarea readonly="readonly" class="installLog">(.*?)</textarea>#s', $paHtml, $paM)) {
        pa_say('setup reported: ' . trim(html_entity_decode($paM[1])));
    }

    $paRes = @mysqli_query($paConn, 'SELECT COUNT(*) FROM `' . PA_PREFIX . 'prefs`');
    if (!$paRes || (int) mysqli_fetch_row($paRes)[0] === 0) {
        pa_fail('setup ran but wrote no prefs row; b1gMail is not installed');
    }
    mysqli_free_result($paRes);
    pa_say('installed - admin user "admin" at ' . $paUrl . 'admin/, password in ~/.panelalpha/b1gmail/b1gmail.env');
}

// Put the environment-reading config back over whatever the wizard wrote.
if (is_string($paConfig) && $paConfig !== '') {
    file_put_contents(PA_APP . '/serverlib/config.inc.php', $paConfig);
}

// prefs.datafolder is where every message body, attachment and webdisk file is
// written. The wizard sets it to <docroot>/data/, inside the checkout that a
// redeploy empties; move it onto the mount that survives one.
if (is_dir(PA_DATA_DIR) && is_writable(PA_DATA_DIR)) {
    $paUrlBase = rtrim((string) getenv('URL'), '/');
    $paSql = 'UPDATE `' . PA_PREFIX . "prefs` SET `datafolder`='" . mysqli_real_escape_string($paConn, PA_DATA_DIR) . "'";
    if ($paUrlBase !== '') {
        // Fix up the public URL too, so moving the project to another domain
        // is a redeploy rather than a manual ACP edit.
        $paSql .= ", `selfurl`='" . mysqli_real_escape_string($paConn, $paUrlBase . '/') . "'"
            . ", `mobile_url`='" . mysqli_real_escape_string($paConn, $paUrlBase . '/m/') . "'";
    }
    if (!mysqli_query($paConn, $paSql)) {
        pa_fail('could not point prefs.datafolder at ' . PA_DATA_DIR . ': ' . mysqli_error($paConn));
    }
    pa_say('mail store: ' . PA_DATA_DIR . ' (bind mount from ~/.panelalpha/b1gmail/data)');
} else {
    pa_say('WARNING: ' . PA_DATA_DIR . ' is not a writable mount; message bodies will be written inside ~/project and the next redeploy will delete them');
}

// Upstream says to delete it after installing, and it is the one directory that
// can re-run the installer against a live database. It comes back with every
// clone, so this runs on an upgrade too.
pa_rmtree(PA_APP . '/setup');
pa_say(is_dir(PA_APP . '/setup') ? 'WARNING: could not remove src/setup' : 'removed src/setup');

pa_say('ready');
