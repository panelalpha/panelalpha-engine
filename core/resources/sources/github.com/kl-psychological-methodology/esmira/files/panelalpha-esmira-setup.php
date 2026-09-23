<?php

/**
 * ESMira install / upgrade, headless.
 *
 * Runs inside the app container on the install and upgrade stages, as the
 * account uid, with /app as the working directory -- before Apache binds. It
 * does what a human would do in ESMira's first-run wizard, by calling the
 * wizard's own code rather than by reimplementing it:
 *
 *   backend\admin\features\noPermission\InitESMira
 *     -> backend\fileSystem\ESMiraInitializerFS::getConfigAdditions()
 *     -> backend\FileSystemBasics::writeServerConfigs()
 *     -> backend\fileSystem\ESMiraInitializerFS::create()
 *          -> AccountStoreFS::setAccount()   (password_hash, PASSWORD_DEFAULT)
 *          -> PermissionsLoader::exportFile() (admin => true)
 *
 * InitESMira itself is three lines of request plumbing around those calls
 * (a $_POST check, and a Permission::setLoggedIn() that only makes sense in a
 * web request), so what is reused is everything underneath it.
 *
 * Why this runs at all: the wizard is first-visitor-wins. `InitESMira` is
 * registered in api/admin.php's `//no permission:` block and its only guard is
 * `Configs::getDataStore()->isInit()` -- so on a server that has never been
 * set up, anyone who can reach the domain can POST
 *
 *   /api/admin.php?type=InitESMira  new_account=...&pass=...&data_location=...
 *
 * and become its administrator, with no authentication of any kind. On an
 * account with a public HTTPS domain the window is however long it takes the
 * owner to open a browser. Finishing the install before Apache serves a
 * request closes it, and the healthcheck then asserts it stayed closed.
 *
 * Idempotent by design: on the upgrade stage an already-initialised server
 * costs one file_exists() and whatever MigrationManager decides, and never
 * touches the account, the password or the data folder.
 */

// ---------------------------------------------------------------------------
// Where the application is.
//
// The document root is ESMira-web/dist, which only exists after the webpack
// build (see the recipe's panelalpha.yaml). backend/autoload.php is what
// defines DIR_BASE -- `dirname(__FILE__, 2) . '/'` -- so requiring it from
// there is what points every Paths:: constant at the built tree rather than at
// the checkout.
$base = '/app/ESMira-web/dist';
$autoload = $base . '/backend/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "[esmira] $autoload is missing: the webpack build did not produce dist/.\n");
    exit(1);
}

require_once $autoload;

use backend\Configs;
use backend\FileSystemBasics;
use backend\MigrationManager;
use backend\Paths;

// ---------------------------------------------------------------------------
// Where the account's data is.
//
// /data is a bind mount of ~/.panelalpha/esmira/data, declared by the compose
// override. Two things follow from it and both matter:
//
//   1. it survives a redeploy. The engine empties ~/project before every clone
//      (engine #173), and ESMira's own default data location is DIR_BASE --
//      i.e. inside the document root, inside the checkout -- so on the default
//      the account would lose every study and every collected response on each
//      redeploy.
//   2. it is not under the document root, so no study file, no response file,
//      no .logins and no .permissions has a URL at all. Upstream's own answer
//      is an `.htaccess` holding `Deny from all` written into esmira_data
//      (ESMiraInitializerFS::createDataFolder()); that is one AllowOverride
//      away from being a no-op, and this is research data about people.
//
// assembleDataFolderPath() appends 'esmira_data/' and refuses a path that does
// not exist, which is why hooks/prepare.sh creates the directory before
// compose runs.
$dataLocation = getenv('ESMIRA_DATA_LOCATION') ?: '/data';

if (!is_dir($dataLocation)) {
    fwrite(STDERR, "[esmira] data location $dataLocation does not exist.\n");
    exit(1);
}

$store = Configs::getDataStore();

// ---------------------------------------------------------------------------
// Upgrade: an initialised server. Do not touch anything but the migrations.
//
// This is the same call upstream's own docker-entrypoint.sh makes on every
// boot. It is a no-op unless dist/VERSION has moved past esmira_data/VERSION,
// in which case it runs the matching scripts out of
// backend/admin/updateScripts/ and rewrites esmira_data/VERSION.
//
// It runs here rather than at boot because the checkout is re-cloned and
// rebuilt on every deploy, so the version can only move at a deploy.
if ($store->isInit()) {
    echo "[esmira] already initialised (" . Configs::get('dataFolder_path') . "); checking migrations\n";
    MigrationManager::autoRun();
    echo "[esmira] migrations up to date\n";

    // One honest warning, and the reason it exists.
    //
    // ~/.panelalpha/esmira/data and ~/.panelalpha/esmira-app.env are two
    // separate things in the same directory, and losing only the second is
    // possible -- a partial restore, a hand-edit, an account moved between
    // hosts. hooks/prepare.sh would then mint a *new* password and write a new
    // credentials file, this branch would skip the install because the data
    // folder is still there, and the file would confidently state a password
    // that opens nothing. The hash is bcrypt, so it cannot be repaired from
    // here; saying so in the deploy log is the whole remedy.
    $account = getenv('ESMIRA_ADMIN_USER') ?: '';
    $password = getenv('ESMIRA_ADMIN_PASS') ?: '';
    if ($account !== '' && $password !== '') {
        $accounts = $store->getAccountStore();
        if (!$accounts->doesAccountExist($account)) {
            fwrite(STDERR, "[esmira] warning: the data folder has no account '$account'. "
                . "~/.panelalpha/esmira-admin-credentials.txt does not describe this server.\n");
        } elseif (!$accounts->checkAccountLogin($account, $password)) {
            fwrite(STDERR, "[esmira] warning: the password in ~/.panelalpha/esmira-app.env no longer "
                . "opens account '$account'. Either it was changed in the web interface -- in which "
                . "case this is expected and ~/.panelalpha/esmira-admin-credentials.txt is simply out "
                . "of date -- or that file was lost and regenerated, in which case the real password "
                . "is not recoverable from here.\n");
        }
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// Install: the credentials.
//
// Written by hooks/prepare.sh into ~/.panelalpha/esmira-app.env and delivered
// by env_file:, so they are outside ~/project (engine #173) and outside .env,
// which ProjectEnvironment::apply() copies to .env.default at mode 644.
$account = getenv('ESMIRA_ADMIN_USER') ?: '';
$password = getenv('ESMIRA_ADMIN_PASS') ?: '';

if ($account === '' || $password === '') {
    fwrite(STDERR, "[esmira] ESMIRA_ADMIN_USER / ESMIRA_ADMIN_PASS are not set. "
        . "env_file: is read at container creation, so a value written after "
        . "the container existed never arrives.\n");
    exit(1);
}

// AccountStoreFS::isAccountNameValid() rejects only a ':' -- the .logins file
// is `name:hash` per line. Said here too so the failure is a message rather
// than a half-written install.
if (str_contains($account, ':')) {
    fwrite(STDERR, "[esmira] the account name must not contain ':'.\n");
    exit(1);
}

$initializer = $store->getESMiraInitializer();

// getConfigAdditions() reads $_POST['data_location']: it is the wizard's own
// input, and this script is the wizard. Nothing else in the call path looks at
// the request.
$_POST['data_location'] = $dataLocation;

try {
    // Order matters and is upstream's: writeServerConfigs() first, because
    // create() reads Configs::get('dataFolder_path') back out of it.
    $config = $initializer->getConfigAdditions();

    // A server name, so the page has a title before anyone logs in. Merged
    // into the same write rather than done as a second one: writeServerConfigs()
    // rewrites the whole file from defaults + current + new.
    $serverName = getenv('ESMIRA_SERVER_NAME');
    if (is_string($serverName) && trim($serverName) !== '') {
        $config['serverName'] = ['en' => trim($serverName)];
    }

    FileSystemBasics::writeServerConfigs($config);
    $initializer->create($account, $password);
} catch (Throwable $e) {
    // The same rollback InitESMira does: a configs.php pointing at a data
    // folder that was never finished makes isInit() true and locks the wizard
    // out of a server that has no account in it.
    if (is_file(Paths::FILE_CONFIG)) {
        FileSystemBasics::deleteServerConfigs();
    }
    fwrite(STDERR, '[esmira] install failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// The VERSION stamp create() copies is what MigrationManager compares against
// on later deploys; say so in the log so a later upgrade is readable.
echo "[esmira] installed: data folder " . Configs::get('dataFolder_path')
    . ", admin account '" . $account . "', server version "
    . (is_file(Paths::FILE_SERVER_VERSION) ? trim((string) file_get_contents(Paths::FILE_SERVER_VERSION)) : '?')
    . "\n";
echo "[esmira] the first-run wizard is now closed (InitESMira answers 'Disabled')\n";
