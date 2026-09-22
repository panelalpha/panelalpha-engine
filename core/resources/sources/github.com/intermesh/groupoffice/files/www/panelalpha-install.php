<?php
/*
 * Group Office installation and configuration, driven from the deploy instead
 * of from a visitor.
 *
 * Group Office's web installer is `www/install/` and it is entirely
 * unauthenticated. Three of its steps matter here:
 *
 *   install/configfile.php  a form that writes www/config.php from POST data --
 *                           database host, name, user and password, plus the
 *                           data and temp paths -- with no authentication at
 *                           all (configfile.php:103-112). The first stranger to
 *                           reach a fresh deploy can point the installation at
 *                           a database they control.
 *   install/install.php     the admin form. Whoever posts it becomes the
 *                           System Administrator (install.php:71-85).
 *   install/upgrade.php     schema migrations, also unauthenticated, and
 *                           reachable for the rest of the installation's life,
 *                           not just before it is installed.
 *
 * So the whole `install/` tree is denied in files/www/.htaccess and the work it
 * would have done runs here: in a CLI process on the install stage, before
 * Apache binds, with a password generated per account by hooks/prepare.sh.
 *
 * Nothing here reimplements Group Office. `install` bootstraps upstream's own
 * framework exactly as www/install/install.php does -- require vendor/autoload,
 * App::get(), a TemporaryState auth state -- and then calls upstream's own
 * `App::get()->getInstaller()->install($admin)`, the legacy-module loop and the
 * two CronJob rows that install.php creates after it. `upgrade` calls
 * upstream's `Installer::upgrade()`, the same call `core/System/upgrade` makes.
 *
 * Modes:
 *   config   write www/config.php from the database the engine provisioned.
 *            Pure file writing: it must run before anything bootstraps the
 *            framework, because App::get() reads the config immediately.
 *   check    print `installed=0|1` and exit 0. Speaks PDO directly, so it works
 *            before Group Office exists.
 *   version  print `schema=` and `code=` for the upgrade decision.
 *   install  create the schema, the groups, the administrator and the modules.
 *   upgrade  run upstream's migrations when the checkout is newer than the
 *            schema, and drop the compiled client-script cache either way.
 *
 * Everything this file prints goes to stderr, and that is not a style rule:
 * install/install.php and clearcache.php both end by rebuilding caches through
 * code that will happily try to send headers, and under the CLI SAPI
 * headers_sent() becomes true on the first byte written to stdout. Only the
 * `check` and `version` modes write to stdout, because panelalpha-setup.sh
 * eval's them.
 */

declare(strict_types=1);

const PA_CONFIG_FILE = '/app/config.php';
const PA_PASSWORD_FILE = '/app/.panelalpha-admin-password';

function pa_say(string $message): void
{
    fwrite(STDERR, '[groupoffice] ' . $message . PHP_EOL);
}

function pa_fail(string $message): never
{
    fwrite(STDERR, '[groupoffice] ' . $message . PHP_EOL);
    exit(1);
}

/** @return array{host:string,port:int,name:string,user:string,pass:string} */
function pa_db(): array
{
    $name = (string) getenv('DB_DATABASE');
    $user = (string) getenv('DB_USERNAME');
    if ($name === '' || $user === '') {
        pa_fail('no DB_DATABASE/DB_USERNAME in the environment; is `database: mysql` still in panelalpha.yaml?');
    }

    return [
        'host' => (string) (getenv('DB_HOST') ?: 'localhost'),
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => $name,
        'user' => $user,
        'pass' => (string) getenv('DB_PASSWORD'),
    ];
}

/**
 * www/config.php, as a list of getenv() calls.
 *
 * The file is inside the document root -- Group Office serves from www/ and
 * App::findConfigFile() looks for `<install dir>/config.php`
 * (go/core/App.php:936) -- so it holds no literal secret: Apache executes it
 * rather than disclosing it, but a config.php full of getenv() is one that
 * cannot leak a password even if some future misconfiguration served it as
 * text. It is also rewritten on every deploy rather than preserved, because
 * every deploy re-clones ~/project (engine#173) and there is never a file here
 * to keep.
 */
function pa_write_config(): void
{
    $php = <<<'PHP'
<?php
/*
 * Written by PanelAlpha on every deploy. Do not edit: the next deploy
 * overwrites it.
 *
 * getenv() rather than values, so the database password is never on disk. The
 * engine hands the container DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and
 * DB_PASSWORD for the database it provisioned on the account's own MySQL
 * server.
 */

$config['db_host'] = getenv('DB_HOST') ?: 'localhost';
$config['db_port'] = (int) (getenv('DB_PORT') ?: 3306);
$config['db_name'] = getenv('DB_DATABASE');
$config['db_user'] = getenv('DB_USERNAME');
$config['db_pass'] = getenv('DB_PASSWORD');

// /data is ~/.panelalpha/groupoffice, bind-mounted by the compose override.
// Every uploaded file, every mail attachment and every blob lands under
// file_storage_path (go/core/fs/Blob.php:380-384), so it has to be somewhere a
// redeploy cannot reach: ~/project is cleared and re-cloned (engine#173).
$config['file_storage_path'] = '/data/files/';
$config['tmpdir'] = '/data/tmp/';

$config['debug'] = false;
// Nothing on a hosted instance should be phoning intermesh.nl to ask whether a
// newer Group Office exists; the account upgrades by redeploying.
$config['checkForUpdates'] = false;
// Server-sent events hold one Apache worker open per signed-in browser tab.
// The base image is mod_php/prefork with a worker count sized for ordinary
// requests, so a handful of open tabs would take the site down. Group Office
// falls back to polling, which is what upstream's own config.php.example says
// to do on a server that cannot spare the connections.
$config['sseEnabled'] = false;
PHP;

    if (file_put_contents(PA_CONFIG_FILE, $php . PHP_EOL) === false) {
        pa_fail('could not write ' . PA_CONFIG_FILE);
    }
    chmod(PA_CONFIG_FILE, 0640);
    pa_say('wrote ' . PA_CONFIG_FILE);
}

function pa_pdo(): PDO
{
    $db = pa_db();

    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * Installed means the database says so, not the checkout.
 *
 * `core_user` plus a row in it: Installer::install() creates the tables from
 * go/core/install/install.sql and then the administrator, so a run that died
 * between the two leaves tables and no user -- which is not an installation and
 * must not be reported as one.
 */
function pa_installed(): bool
{
    try {
        $pdo = pa_pdo();
    } catch (Throwable $e) {
        pa_say('database not reachable yet: ' . $e->getMessage());

        return false;
    }

    try {
        $table = $pdo->query("SHOW TABLES LIKE 'core_user'")->fetch();
        if (!$table) {
            return false;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM core_user')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function pa_bootstrap(): void
{
    require '/app/vendor/autoload.php';
    \go\core\App::get();
}

function pa_password(): string
{
    if (!is_readable(PA_PASSWORD_FILE)) {
        pa_fail(PA_PASSWORD_FILE . ' is missing; hooks/prepare.sh should have written it');
    }
    $password = trim((string) file_get_contents(PA_PASSWORD_FILE));
    if (strlen($password) < 6) {
        pa_fail('the generated administrator password is shorter than Group Office allows');
    }

    return $password;
}

/**
 * The compiled client bundle, which lives with the data rather than with the
 * code.
 *
 * Extjs3::loadScripts() caches the concatenated ExtJS/GOUI JavaScript at
 * `<file_storage_path>/cache/clientscripts/all.js` (go/core/webclient/Extjs3.php:297).
 * That path is the bind mount, so it survives a redeploy -- including a
 * redeploy that moved the checkout to a newer Group Office, which would then
 * serve last version's JavaScript against this version's PHP. Dropped on every
 * install and upgrade; it rebuilds itself on the next page load.
 */
function pa_drop_client_cache(): void
{
    $dir = '/data/files/cache/clientscripts';
    if (!is_dir($dir)) {
        return;
    }
    // Recursive: the directory has a per-theme subdirectory (Paper/style.css)
    // beside all.js and the language bundles, and leaving that behind is the
    // half that shows as an unstyled page rather than as a broken one.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    pa_say('dropped the compiled client-script cache');
}

function pa_install(): void
{
    pa_bootstrap();

    $db = pa_db();
    $admin = [
        'displayName' => 'System Administrator',
        'username' => getenv('GO_ADMIN_USER') ?: 'admin',
        'password' => pa_password(),
        'email' => getenv('GO_ADMIN_EMAIL') ?: ('admin@' . (parse_url((string) getenv('APP_URL'), PHP_URL_HOST) ?: 'localhost')),
        'language' => 'en',
    ];

    pa_say('installing Group Office ' . go()->getVersion() . " into {$db['name']} for '{$admin['username']}'");

    // install/install.php:69. Everything below runs as a user that does not
    // exist yet, so there is no ACL to satisfy.
    go()->disableEvents();
    \go\core\App::get()->setAuthState(new \go\core\auth\TemporaryState());

    \go\core\App::get()->getInstaller()->install($admin);

    // install/install.php:88-104: the modules that predate the core Module
    // class install through the legacy manager instead. Upstream swallows a
    // failure here because an unlicensed module can drag a licensed dependency
    // down with it; the same applies to a community-edition deploy, where the
    // business modules are simply absent.
    \GO::$ignoreAclPermissions = true;
    foreach (\GO::modules()->getAvailableModules() as $moduleClass) {
        $controller = $moduleClass::get();
        if ($controller instanceof \go\core\Module) {
            continue;
        }
        if ($controller->autoInstall() && $controller->isInstallable()) {
            try {
                \GO\Base\Model\Module::install($controller->name(), false, $controller::getDefaultSortOrder());
            } catch (Throwable $e) {
                \go\core\ErrorHandler::logException($e);
                pa_say('legacy module ' . $controller->name() . ' not installed: ' . $e->getMessage());
            }
        }
    }

    // install/install.php:107-142.
    foreach ([
        ['Email Reminders', '*/5', '*', \GO\Base\Cron\EmailReminders::class],
        ['Calculate disk usage', '1', '1', \GO\Base\Cron\CalculateDiskUsage::class],
    ] as [$name, $minutes, $hours, $job]) {
        $cron = new \GO\Base\Cron\CronJob();
        $cron->name = $name;
        $cron->active = true;
        $cron->runonce = false;
        $cron->minutes = $minutes;
        $cron->hours = $hours;
        $cron->monthdays = '*';
        $cron->months = '*';
        $cron->weekdays = '*';
        $cron->job = $job;
        if (!$cron->save()) {
            pa_fail('could not save the ' . $name . ' cron job: ' . var_export($cron->getValidationErrors(), true));
        }
    }

    \GO\Base\Observable::cacheListeners();
    \go\core\model\User::findById(1)->legacyOnSave();

    // The account's own https address, so links in reminders and invitations
    // point at the site rather than at whatever Host header built them.
    $url = trim((string) getenv('APP_URL'));
    if ($url !== '') {
        go()->getSettings()->URL = rtrim($url, '/') . '/';
        go()->getSettings()->save();
    }

    go()->rebuildCache();
    pa_drop_client_cache();

    pa_say('installed');
}

function pa_upgrade(): void
{
    pa_bootstrap();

    $schema = (string) go()->getSettings()->databaseVersion;
    $code = (string) go()->getVersion();
    if ($schema === $code) {
        pa_say("schema {$schema} matches the checkout");
        pa_drop_client_cache();

        return;
    }

    pa_say("upgrading the schema from {$schema} to {$code}");
    if (version_compare($schema, \go\core\Installer::MIN_UPGRADABLE_VERSION, '<')) {
        pa_fail(
            "this account's schema is {$schema} and Group Office only upgrades from "
            . \go\core\Installer::MIN_UPGRADABLE_VERSION . ' onwards'
        );
    }

    // cli/controller/System::upgrade(), minus its clearCache(), which asks the
    // webserver for /install/clearcache.php -- a URL this recipe denies, and one
    // that is not listening yet anyway: this runs before Apache binds.
    go()->getInstaller()->isValidDb();
    go()->getDatabase()->clearCache();
    \GO::session()->runAsRoot();
    date_default_timezone_set('UTC');
    go()->getInstaller()->upgrade();
    go()->rebuildCache();
    pa_drop_client_cache();

    pa_say('upgraded');
}

$mode = $argv[1] ?? '';

switch ($mode) {
    case 'config':
        pa_write_config();
        break;

    case 'check':
        echo 'installed=' . (pa_installed() ? '1' : '0') . PHP_EOL;
        break;

    case 'version':
        pa_bootstrap();
        echo 'schema=' . go()->getSettings()->databaseVersion . PHP_EOL;
        echo 'code=' . go()->getVersion() . PHP_EOL;
        break;

    case 'install':
        pa_install();
        break;

    case 'upgrade':
        pa_upgrade();
        break;

    default:
        pa_fail('usage: panelalpha-install.php config|check|version|install|upgrade');
}
