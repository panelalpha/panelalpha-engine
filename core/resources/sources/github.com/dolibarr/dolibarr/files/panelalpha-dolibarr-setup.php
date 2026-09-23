<?php

/**
 * Finish Dolibarr's own install wizard from the command line, before Apache
 * binds, and keep it finished across redeploys.
 *
 * Runs inside the application container on the `install` and `upgrade` stages,
 * as the account uid, with /data bind-mounted from ~/.panelalpha/dolibarr.
 *
 * It does not reimplement the wizard. Dolibarr's install pages each read
 * $argv when GETPOST comes back empty -- step1.php:60-84, step2.php:62,
 * step5.php:84-153, upgrade.php:88-91, upgrade2.php:104-106 -- and
 * install/inc.php:140 has a getopt() block and an install_usage() for exactly
 * this. So the wizard is driven, not copied, and every version-specific
 * migration stays upstream's.
 *
 * Idempotent by design: each phase is guarded by a question asked of the
 * database or the filesystem, so the upgrade stage over a live account costs
 * three SELECTs and nothing else.
 *
 * Lives at /app/panelalpha-dolibarr-setup.php -- a sibling of the document
 * root (htdocs/), so it is not web-reachable even before
 * apache-vhost.stub's `^panelalpha[-.]` FilesMatch has an opinion.
 */

const DATA = '/data';
const DOCUMENTS = DATA . '/documents';
const CUSTOM = DATA . '/custom';
const CONF_STORE = DATA . '/conf.php';
const HTDOCS = '/app/htdocs';
const INSTALL_DIR = HTDOCS . '/install';
const CONF_IN_TREE = HTDOCS . '/conf/conf.php';

/** Dolibarr's own name for "no previous version"; only used to label the install. */
const FRESH_FROM = '0.0.0';

function say(string $line): void
{
    fwrite(STDERR, '[dolibarr] ' . $line . "\n");
}

function fail(string $line): never
{
    fwrite(STDERR, '[dolibarr] FAILED: ' . $line . "\n");
    exit(1);
}

/**
 * One install page, run the way install/inc.php expects to be run.
 *
 * cwd matters and is not cosmetic: inc.php:36 requires '../filefunc.inc.php'
 * and inc.php:40 defines DOL_DOCUMENT_ROOT as the literal '..', and
 * inc.php:73 sets $conffile to '../conf/conf.php'. All three are relative, so
 * the scripts only resolve anything from inside install/.
 *
 * Output is captured rather than streamed: these pages print a full HTML
 * document, and a deploy log is not a browser. Only the tail is shown, and
 * only when the page failed.
 *
 * @param list<string> $args positional arguments, in the order the page reads $argv
 */
function step(string $script, array $args): void
{
    $cmd = 'cd ' . escapeshellarg(INSTALL_DIR) . ' && php ' . escapeshellarg($script);
    foreach ($args as $arg) {
        // An empty argument still has to occupy its position: these pages read
        // $argv by fixed index, so a skipped one shifts everything after it.
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $text = strip_tags(implode("\n", $output));
        $text = trim(preg_replace('/\n{2,}/', "\n", $text) ?? '');
        say($script . ' output (tail):');
        fwrite(STDERR, substr($text, -2000) . "\n");
        fail($script . ' exited ' . $code);
    }
    say($script . ' ok');
}

/** The account's database, on the account's own MySQL server. */
function connect(): mysqli
{
    $host = getenv('DB_HOST') ?: '';
    $name = getenv('DB_DATABASE') ?: '';
    $user = getenv('DB_USERNAME') ?: '';
    $pass = getenv('DB_PASSWORD');
    $port = (int) (getenv('DB_PORT') ?: '3306');
    if ($host === '' || $name === '' || $user === '') {
        fail('no database in the environment; the manifest must declare `database: mysql`');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    // The engine provisions the database before the container starts
    // (AppDatabase::provision), so this is a connection to something that
    // already exists -- but the account's MySQL is a separate container and a
    // cold one can refuse the first connection.
    for ($try = 1; $try <= 30; $try++) {
        $db = @new mysqli($host, $user, (string) $pass, $name, $port);
        if ($db->connect_errno === 0) {
            return $db;
        }
        if ($try === 1) {
            say('waiting for MySQL at ' . $host . ':' . $port);
        }
        sleep(2);
    }

    fail('could not reach MySQL at ' . $host . ':' . $port);
}

function tableExists(mysqli $db, string $table): bool
{
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");

    return $result !== false && $result->num_rows > 0;
}

function constant_value(mysqli $db, string $name): ?string
{
    $result = $db->query(
        "SELECT value FROM llx_const WHERE name = '" . $db->real_escape_string($name) . "' LIMIT 1"
    );
    if ($result === false || $result->num_rows === 0) {
        return null;
    }
    /** @var array{value: ?string} $row */
    $row = $result->fetch_assoc();

    return $row['value'] !== null && trim($row['value']) !== '' ? trim($row['value']) : null;
}

/** The version the schema was last brought to, whichever way it got there. */
function schemaVersion(mysqli $db): ?string
{
    return constant_value($db, 'MAIN_VERSION_LAST_UPGRADE')
        ?? constant_value($db, 'MAIN_VERSION_LAST_INSTALL');
}

/** The version of the code that is on disk right now, out of htdocs/version.inc.php. */
function codeVersion(): string
{
    $out = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg(HTDOCS)
        . ' && php -r ' . escapeshellarg('define("DOL_INC_FOR_VERSION_ERROR",1); require "version.inc.php"; echo DOL_VERSION;'),
        $out,
        $code
    );
    $version = trim(implode('', $out));
    if ($code !== 0 || $version === '') {
        fail('could not read DOL_VERSION out of htdocs/version.inc.php');
    }

    return $version;
}

// ---------------------------------------------------------------------------
// 0. The mount, which is the whole persistence story.

if (!is_dir(DATA) || !is_writable(DATA)) {
    fail(DATA . ' is not a writable directory — the compose override\'s bind mount did not arrive');
}
foreach ([DOCUMENTS, CUSTOM] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        fail('could not create ' . $dir);
    }
}

// hooks/prepare.sh links htdocs/conf/conf.php at CONF_STORE before the
// container starts. Without that link the wizard's own redirect at
// filefunc.inc.php:222 sends every visitor to install/index.php, which is the
// thing this script exists to close.
if (!is_link(CONF_IN_TREE) || readlink(CONF_IN_TREE) !== CONF_STORE) {
    fail(CONF_IN_TREE . ' is not the link to ' . CONF_STORE . ' that hooks/prepare.sh writes');
}

$adminLogin = getenv('DOLI_ADMIN_LOGIN') ?: 'admin';
$adminPass = (string) getenv('DOLI_ADMIN_PASS');
$url = getenv('APP_URL') ?: '';
if ($url === '') {
    fail('APP_URL is empty; Dolibarr records it as dolibarr_main_url_root and builds every link from it');
}

// ---------------------------------------------------------------------------
// 1. conf.php, through step1.php, once.
//
// Only when the stored file has no configuration in it. step1 mints
// $dolibarr_main_instance_unique_id with random_bytes(32) (step1.php:911) and
// that value is the seed for dolcrypt; rewriting conf.php on a redeploy would
// rotate it and make everything the account had encrypted unreadable.
//
// The `> 8` test is Dolibarr's own (install/inc.php:207): a conf.php holding
// only `<?php` is "not configured yet".

$configured = is_file(CONF_STORE) && filesize(CONF_STORE) > 8;
if (!$configured) {
    if ($adminPass === '') {
        fail('DOLI_ADMIN_PASS is empty; hooks/prepare.sh generates it into ~/.panelalpha/dolibarr-app.env');
    }
    say('writing conf.php (first install)');
    step('step1.php', [
        'set',                              // $argv[1]  action
        'en_US',                            // $argv[2]  selectlang
        HTDOCS,                             // $argv[3]  main_dir
        DOCUMENTS,                          // $argv[4]  main_data_dir
        $url,                               // $argv[5]  main_url
        '',                                 // $argv[6]  db root login  — not used
        '',                                 // $argv[7]  db root pass   — not used
        'mysqli',                           // $argv[8]  db_type
        (string) getenv('DB_HOST'),         // $argv[9]
        (string) getenv('DB_DATABASE'),     // $argv[10]
        (string) getenv('DB_USERNAME'),     // $argv[11]
        (string) getenv('DB_PASSWORD'),     // $argv[12]
        (string) (getenv('DB_PORT') ?: '3306'), // $argv[13]
        'llx_',                             // $argv[14] db_prefix
        '',                                 // $argv[15] create database — the engine did
        '',                                 // $argv[16] create db user  — the engine did
    ]);
    // step1.php:567 copies the previous conf.php to conf.php.old at mode 0400
    // before writing. Here "the previous one" is the empty file prepare.sh
    // touched, so the copy is an empty file with no reason to exist — and it
    // lands beside the *link*, in the checkout, because $conffile there is
    // install/inc.php's relative '../conf/conf.php'.
    @unlink(HTDOCS . '/conf/conf.php.old');
    @chmod(CONF_STORE, 0600);
}

$db = connect();

// ---------------------------------------------------------------------------
// 2. The schema, through step2.php, once.

if (!tableExists($db, 'llx_const')) {
    say('loading the schema');
    step('step2.php', ['set', 'en_US']);
}

// ---------------------------------------------------------------------------
// 3. The administrator and the lock, through step5.php, once.
//
// This is the step that closes the wizard. step5.php:531-546 writes
// DOL_DATA_ROOT/install.lock, and install/inc.php:319-335 refuses every page
// under install/ while that file exists. DOL_DATA_ROOT is /data/documents,
// outside the checkout, so the lock survives the redeploy that empties
// ~/project (engine #173) — which is the difference between locking the
// wizard and locking it until Tuesday.

$code = codeVersion();
$installed = schemaVersion($db);

if ($installed === null) {
    if ($adminPass === '') {
        fail('DOLI_ADMIN_PASS is empty; cannot create the administrator');
    }
    say('creating the administrator and locking install/');
    step('step5.php', [
        FRESH_FROM,   // $argv[1] versionfrom — label only; `set` targets DOL_VERSION
        $code,        // $argv[2] versionto
        'en_US',      // $argv[3] selectlang
        'set',        // $argv[4] action
        $adminLogin,  // $argv[5] login
        $adminPass,   // $argv[6] pass
        $adminPass,   // $argv[7] pass_verif
        '1',          // $argv[8] installlock
    ]);
    $installed = schemaVersion($db);
}

// ---------------------------------------------------------------------------
// 4. The migration, when the checkout has moved past the database.
//
// Every deploy re-clones the branch, so this happens by itself the first time
// upstream bumps a version. Running it here rather than leaving install/
// reachable is the whole reason denying that URL is safe: upstream's upgrade
// pages carry no authentication of their own by deliberate choice, and they
// run schema migrations.
//
// upgrade.php:41, upgrade2.php:44 and step5.php:41 each define
// ALLOWED_IF_UPGRADE_UNLOCK_FOUND, which install/inc.php:333 honours only
// while an `upgrade.unlock` file sits beside install.lock. Created here and
// removed in the same run, so the window is this script and not the deploy.

if ($installed !== null && $installed !== $code) {
    say('migrating ' . $installed . ' -> ' . $code);
    $unlock = DOCUMENTS . '/upgrade.unlock';
    if (@file_put_contents($unlock, "PanelAlpha deploy, removed at the end of this run.\n") === false) {
        fail('could not write ' . $unlock);
    }
    try {
        step('upgrade.php', [$installed, $code, 'ignoredbversion']);
        step('upgrade2.php', [$installed, $code]);
        step('step5.php', [$installed, $code, 'en_US', 'upgrade']);
    } finally {
        @unlink($unlock);
    }
    $installed = schemaVersion($db);
    say('database now at ' . ($installed ?? 'unknown'));
}

// ---------------------------------------------------------------------------
// 5. The two conf.php values that are about this deploy rather than about
//    this account, rewritten every time.
//
// dolibarr_main_url_root is what Dolibarr builds every link, redirect and
// e-mail from, and a project's domain can change under it. dolibarr_main_prod
// is written as '0' by step1.php:615 — the value that leaves PHP warnings
// rendered into the page — and there is no argument or form field for it.
//
// The chmod is load-bearing and was found by measurement. htdocs/index.php:176
// strips the write bits from conf.php on *every* load of the home page
// (`$newPerm = $currentPerm & ~0222` then dolChmod), so a deployed account's
// conf.php is 0400 within one request of coming up. Without restoring the bit
// first, the one deploy that actually needs this block — the one after the
// project's domain changed — would fail here on a file it owns.

@chmod(CONF_STORE, 0600);
$conf = (string) @file_get_contents(CONF_STORE);
if ($conf === '') {
    fail('conf.php is empty after the install');
}
$before = $conf;
$conf = preg_replace(
    '/^\$dolibarr_main_url_root\s*=.*$/m',
    '$dolibarr_main_url_root=' . var_export($url, true) . ';',
    $conf
) ?? $conf;
$conf = preg_replace(
    '/^\$dolibarr_main_prod\s*=.*$/m',
    "\$dolibarr_main_prod='1';",
    $conf
) ?? $conf;
if ($conf !== $before) {
    if (@file_put_contents(CONF_STORE, $conf) === false) {
        fail('could not rewrite ' . CONF_STORE);
    }
    say('conf.php: url_root=' . $url . ', prod=1');
}
@chmod(CONF_STORE, 0600);

// ---------------------------------------------------------------------------
// 6. The lock, restated.
//
// step5 writes it, but only on the run that created the administrator. An
// account restored from a backup of the database alone, or one whose
// documents directory was replaced, would otherwise come back up with the
// wizard open — and the wizard is the whole security story here.

$lock = DOCUMENTS . '/install.lock';
if (!is_file($lock)) {
    @file_put_contents($lock, "Locked by the PanelAlpha Dolibarr recipe.\n");
    @chmod($lock, 0444);
    say('recreated ' . $lock);
}

say('ready: Dolibarr ' . $code . ', admin login "' . $adminLogin . '", documents in ' . DOCUMENTS);
$db->close();
