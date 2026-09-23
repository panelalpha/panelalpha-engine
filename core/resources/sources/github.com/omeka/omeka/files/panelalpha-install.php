<?php
/**
 * Installs Omeka Classic, or migrates it, from the command line.
 *
 * Omeka Classic has no installer CLI: install/install.php is a second
 * Zend_Application whose IndexController renders a form, and the only thing
 * standing in front of that form is `SHOW TABLES LIKE 'omeka_options'`. On an
 * account that has just been given a public HTTPS name, that means the first
 * stranger to load /install becomes the site's super user. So the install runs
 * here, from the container's install stage, before Apache binds.
 *
 * It is the installer's own work without the browser. Branch one bootstraps
 * exactly what install/install.php bootstraps -- the same install/application.ini,
 * the same Omeka_Application_Resource_Db pointed at the same db.ini -- fills
 * the same Omeka_Form_Install that IndexController::indexAction() validates,
 * and hands it to the same Installer_Default. Nothing here reimplements a
 * task: the schema, the super user, the migration table and the options all
 * come from Installer_Task_*.
 *
 * Branch two is what UpgradeController::migrateAction() does when an
 * administrator posts the /admin/upgrade form -- Omeka_Db_Migration_Manager
 * migrate() and finalizeDbUpgrade() -- and it is not optional. While a
 * migration is pending, Omeka_Controller_Plugin_Upgrade::dispatchLoopStartup()
 * answers every public request with
 * `die("Public site is unavailable until the upgrade completes.")`, so a code
 * change that ships a migration would take the site down until somebody found
 * the admin form. The upgrade stage replays this on every redeploy.
 *
 * The two branches cannot share a process. install/constants.php defines
 * INSTALL and points APPLICATION_PATH at install/, which changes the path
 * arithmetic in bootstrap.php for the whole request; the main application
 * needs the other answer. So which bootstrap to load is decided first, from a
 * plain mysqli connection, before anything of Omeka's is required.
 */

// This file lives in the document root -- Omeka Classic serves from its own
// root -- so it states its own terms as well. The generated vhost denies
// `panelalpha[-.]` and .htaccess rewrites any .php path that is not the front
// controller into index.php, which makes three independent answers to the
// same question.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$baseDir = __DIR__;
$dbIniPath = $baseDir . '/db.ini';

if (!is_readable($dbIniPath)) {
    fwrite(STDERR, "[omeka] db.ini is missing; panelalpha-setup.sh writes it\n");
    exit(1);
}

$dbIni = parse_ini_file($dbIniPath, true);
$dbConf = is_array($dbIni) && isset($dbIni['database']) ? $dbIni['database'] : null;
if (!is_array($dbConf) || !isset($dbConf['host'], $dbConf['username'], $dbConf['dbname'])) {
    fwrite(STDERR, "[omeka] db.ini has no usable [database] section\n");
    exit(1);
}
$prefix = isset($dbConf['prefix']) ? $dbConf['prefix'] : 'omeka_';

// Which branch this is, asked without Omeka loaded: the two bootstraps below
// cannot share a process, so the question has to be settled first. It is a
// fact about the database rather than about a marker file in a checkout the
// next deploy deletes.
mysqli_report(MYSQLI_REPORT_OFF);
$link = @new mysqli(
    $dbConf['host'],
    $dbConf['username'],
    isset($dbConf['password']) ? $dbConf['password'] : '',
    $dbConf['dbname'],
    isset($dbConf['port']) ? (int) $dbConf['port'] : 3306
);
if ($link->connect_errno) {
    fwrite(STDERR, "[omeka] cannot reach the database: {$link->connect_error}\n");
    exit(1);
}

// Installer_Default::isInstalled() asks only whether the options table exists,
// and that is not enough here. Every file in application/schema is
// `CREATE TABLE IF NOT EXISTS`, and MySQL commits DDL implicitly -- so the
// transaction Installer_Default::install() opens does not roll the schema
// back, nor the rows the first three tasks write. An install that dies in the
// fourth task therefore leaves exactly the state upstream reads as
// "installed", and the next deploy would take the migrate branch and replay
// fifteen years of migrations against a schema that is already current
// (measured: `Duplicate column name 'added'` from
// 20100810120000_detachCollectorsFromEntities).
//
// The marker used instead is the last thing a successful install writes. The
// options table carries `omeka_version`: Installer_Task_Migrations puts it
// there empty, and only Installer_Task_Options -- the final task -- fills it
// in, through an INSERT ... ON DUPLICATE KEY UPDATE. A non-empty value means
// install() reached its commit.
$hasOptions = omeka_panelalpha_table_exists($link, $prefix . 'options');
$version = $hasOptions ? omeka_panelalpha_option($link, $prefix . 'options', 'omeka_version') : '';

if ($hasOptions && $version !== '') {
    $link->close();
    omeka_panelalpha_migrate($baseDir);
    exit(0);
}

if ($hasOptions) {
    // An install that never finished. Before dropping anything, make sure
    // there is nothing to lose: an Omeka with no items is a site nobody has
    // used, and one with items is somebody's collection whatever the options
    // table says.
    $items = omeka_panelalpha_row_count($link, $prefix . 'items');
    if ($items > 0) {
        fwrite(
            STDERR,
            "[omeka] the database holds {$items} item(s) but no completed installation"
            . " (the `omeka_version` option is empty). Refusing to touch it: this needs"
            . " a person. Nothing was changed.\n"
        );
        $link->close();
        exit(1);
    }

    $dropped = omeka_panelalpha_drop_prefixed_tables($link, $prefix);
    fwrite(
        STDERR,
        "[omeka] found a half-built schema with no items in it and no way to finish it;"
        . " dropped {$dropped} `{$prefix}` table(s) and installing again\n"
    );
}
$link->close();

omeka_panelalpha_install($baseDir);
exit(0);

/**
 * Whether one table exists in the database db.ini names. information_schema
 * rather than `SHOW TABLES LIKE`, because `_` is a LIKE wildcard and the
 * default prefix is `omeka_`.
 */
function omeka_panelalpha_table_exists(mysqli $link, $table)
{
    $stmt = $link->prepare(
        'SELECT 1 FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/** One option's value, or '' when the row is absent. */
function omeka_panelalpha_option(mysqli $link, $table, $name)
{
    $stmt = $link->prepare(
        'SELECT value FROM `' . str_replace('`', '``', $table) . '` WHERE name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row === null ? '' : trim((string) $row[0]);
}

/** Rows in a table, or 0 when there is no such table at all. */
function omeka_panelalpha_row_count(mysqli $link, $table)
{
    if (!omeka_panelalpha_table_exists($link, $table)) {
        return 0;
    }

    // The table name cannot be bound as a parameter; it came out of
    // information_schema for this database, so it is not attacker-supplied,
    // and it is quoted anyway.
    $result = $link->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
    if ($result === false) {
        return 0;
    }
    $row = $result->fetch_row();

    return (int) $row[0];
}

/** @return int how many tables were dropped */
function omeka_panelalpha_drop_prefixed_tables(mysqli $link, $prefix)
{
    // `_` and `%` escaped so that `omeka_` does not also match `omekaX`.
    $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $prefix) . '%';
    $stmt = $link->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
    );
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    $tables = [];
    while ($row = $result->fetch_row()) {
        $tables[] = '`' . str_replace('`', '``', $row[0]) . '`';
    }
    $stmt->close();

    if ($tables === []) {
        return 0;
    }

    $link->query('SET FOREIGN_KEY_CHECKS = 0');
    $link->query('DROP TABLE IF EXISTS ' . implode(', ', $tables));
    $link->query('SET FOREIGN_KEY_CHECKS = 1');

    return count($tables);
}

/**
 * Bring an existing installation's schema up to the code that was just
 * deployed. Bootstraps the resource list application/scripts/background.php
 * uses -- upstream's own answer to "run Omeka from the command line" -- which
 * is the whole application short of the session, the front controller and the
 * current user.
 */
function omeka_panelalpha_migrate($baseDir)
{
    require_once $baseDir . '/bootstrap.php';
    require_once LIB_DIR . '/Omeka/Application.php';

    $application = new Omeka_Application(APPLICATION_ENV);
    $application->bootstrap([
        'Config', 'Logger', 'Db', 'Options', 'Pluginbroker', 'View', 'Locale',
        'Mail', 'Plugins', 'Jobs', 'Storage', 'Filederivatives',
    ]);

    $manager = Omeka_Db_Migration_Manager::getDefault();

    // dbNeedsUpgrade() compares the stored omeka_version against
    // OMEKA_VERSION and, when the code moved but no migration came with it,
    // records the new version itself and returns false. So this is already
    // the whole of the version bookkeeping.
    if (!$manager->dbNeedsUpgrade()) {
        echo "[omeka] already installed and up to date (version "
            . get_option(Omeka_Db_Migration_Manager::VERSION_OPTION_NAME) . ")\n";

        return;
    }

    if (!$manager->canUpgrade()) {
        fwrite(STDERR, "[omeka] the database needs an upgrade this version cannot perform\n");
        exit(1);
    }

    $manager->migrate();
    $manager->finalizeDbUpgrade();
    echo '[omeka] ran the pending database migrations; now at version ' . OMEKA_VERSION . "\n";
}

/**
 * First deploy: create the schema, the super user and the default options.
 */
function omeka_panelalpha_install($baseDir)
{
    // Exactly install/install.php's preamble. constants.php defines INSTALL,
    // requires bootstrap.php for the path constants and points
    // APPLICATION_PATH at install/, which is where the Bootstrap class and the
    // application.ini this installer runs on live.
    require_once $baseDir . '/install/constants.php';
    require_once LIB_DIR . '/globals.php';
    require_once 'Zend/Application.php';

    $application = new Zend_Application(
        APPLICATION_ENV,
        APPLICATION_PATH . '/application.ini'
    );

    $dbResource = new Omeka_Application_Resource_Db;
    $dbResource->setinipath(BASE_DIR . '/db.ini');
    $application->getBootstrap()->registerPluginResource($dbResource);

    // Not decoration, and not optional. install/application.ini asks for
    // `resources.layout`, Zend's Layout resource bootstraps FrontController,
    // and `pluginPaths.Omeka_Application_Resource` makes that resolve to
    // Omeka_Application_Resource_Frontcontroller -- which bootstraps `Helpers`,
    // a resource the install application does not have. Measured without these
    // two lines: `Zend_Application_Bootstrap_Exception: Resource matching
    // "Helpers" not found`, thrown before the installer was reached.
    // install/install.php registers Zend's own FrontController for exactly
    // this reason; its comment calls the Omeka one "too heavily coupled for
    // use".
    $application->getBootstrap()->registerPluginResource(
        'Zend_Application_Resource_FrontController',
        [
            'controllerDirectory' => APPLICATION_PATH . '/controllers',
            'throwExceptions' => true,
        ]
    );

    // install/install.php's own comment calls this a workaround for a
    // Zend_Application bug: the plugin resources have to be loaded before
    // bootstrap() or the Omeka FrontController resource is picked up instead
    // of Zend's. Kept because this is the same bootstrap.
    $application->getBootstrap()->getPluginResources();
    $application->getBootstrap()->bootstrap();

    // The one thing the browser does for the web installer that has to be done
    // by hand here. Installer_Task_Options stores the public site's navigation,
    // and Omeka_Navigation::getNavigationOptionValueForInstall() builds it out
    // of Omeka_Navigation_Page_Mvc pages whose getHref() assembles a URL
    // through Zend's router. That router only grows its `default` route when
    // Zend_Controller_Front::dispatch() routes a request -- which never happens
    // in a process that does not call run(). Measured without this line:
    // `[omeka] installation failed: Route default is not defined`, after
    // _createSchema() had already committed its CREATE TABLEs.
    Zend_Controller_Front::getInstance()->getRouter()->addDefaultRoutes();

    $db = $application->getBootstrap()->getResource('db');
    if (!$db) {
        fwrite(STDERR, "[omeka] the database resource did not load\n");
        exit(1);
    }

    $credentials = omeka_panelalpha_credentials();
    $email = omeka_panelalpha_admin_email();

    require_once APP_DIR . '/forms/Install.php';
    $form = new Omeka_Form_Install;

    // The shape the browser would POST. IndexController::indexAction() calls
    // `$form->isValid($_POST)` and then `$this->installer->setForm($form)`;
    // Installer_Default reads every one of these back through
    // $form->getValue(), so the names are the form's own and not fields this
    // script invented. The defaults are the ones Omeka_Form_Install declares
    // for itself.
    $values = [
        'username' => $credentials['username'],
        'password' => $credentials['password'],
        'password_confirm' => $credentials['password'],
        'super_email' => $email,
        'site_title' => omeka_panelalpha_site_title(),
        'description' => '',
        'administrator_email' => $email,
        'copyright' => '',
        'author' => '',
        'tag_delimiter' => Omeka_Form_Install::DEFAULT_TAG_DELIMITER,
        'fullsize_constraint' => Omeka_Form_Install::DEFAULT_FULLSIZE_CONSTRAINT,
        'thumbnail_constraint' => Omeka_Form_Install::DEFAULT_THUMBNAIL_CONSTRAINT,
        'square_thumbnail_constraint' => Omeka_Form_Install::DEFAULT_SQUARE_THUMBNAIL_CONSTRAINT,
        'per_page_admin' => Omeka_Form_Install::DEFAULT_PER_PAGE_ADMIN,
        'per_page_public' => Omeka_Form_Install::DEFAULT_PER_PAGE_PUBLIC,
        'show_empty_elements' => 0,
        // Left empty on purpose: application/config/config.ini sets
        // fileDerivatives.strategy to Omeka_File_Derivative_Strategy_Imagick,
        // which uses the PHP extension. This option is only read by the
        // ExternalImageMagick strategy, and there is no `convert` binary in
        // the image for it to point at.
        'path_to_convert' => '',
    ];

    if (!$form->isValid($values)) {
        fwrite(STDERR, "[omeka] the installer form rejected its own values:\n");
        fwrite(STDERR, '  ' . $form->getMessagesAsString() . "\n");
        exit(1);
    }

    $installer = new Installer_Default($db);
    $installer->setForm($form);

    try {
        $installer->install();
    } catch (Exception $e) {
        // Installer_Default::install() runs inside a transaction it only
        // commits on success, so a failure here leaves no half-built schema.
        fwrite(STDERR, '[omeka] installation failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    echo "[omeka] installed; the super user is {$credentials['username']} <{$email}>,"
        . " password in ~/.panelalpha/omeka/admin-credentials\n";
}

/**
 * The generated super-user credentials, from the file hooks/prepare.sh writes
 * outside the checkout. /data is ~/.panelalpha/omeka, bind-mounted by the
 * compose override; a redeploy empties ~/project (engine#173) and would take
 * a password kept there with it, while the user row in the database survived.
 *
 * @return array{username: string, password: string}
 */
function omeka_panelalpha_credentials()
{
    $path = '/data/admin-credentials';
    if (!is_readable($path)) {
        fwrite(STDERR, "[omeka] {$path} is missing; hooks/prepare.sh writes it\n");
        exit(1);
    }

    // Not parse_ini_file(): the file leads with `#` comments for whoever opens
    // it over SFTP, and PHP's ini parser does not accept those.
    $values = ['username' => 'admin', 'password' => ''];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^OMEKA_ADMIN_USERNAME=(.*)$/', $line, $m)) {
            $values['username'] = trim($m[1]);
        } elseif (preg_match('/^OMEKA_ADMIN_PASSWORD=(.*)$/', $line, $m)) {
            $values['password'] = trim($m[1]);
        }
    }

    if ($values['password'] === '') {
        fwrite(STDERR, "[omeka] the generated super-user password is empty\n");
        exit(1);
    }

    return $values;
}

/**
 * An address for the super user and for `administrator_email`.
 *
 * A hook is told no account address, so the account's own public hostname is
 * the closest thing to a real one -- Omeka mails password resets and user
 * invitations from it. Zend_Validate_EmailAddress is asked first because ZF1
 * ships a fixed list of top-level domains and a customer domain under a newer
 * one would fail the installer form; example.com is the obviously-placeholder
 * fallback, and either way an administrator changes this in their own user
 * form.
 */
function omeka_panelalpha_admin_email()
{
    $host = parse_url((string) getenv('APP_URL'), PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        $host = (string) getenv('SERVER_NAME');
    }

    if ($host !== '') {
        $candidate = 'admin@' . $host;
        $validator = new Zend_Validate_EmailAddress;
        if ($validator->isValid($candidate)) {
            return $candidate;
        }
    }

    return 'admin@example.com';
}

/**
 * `site_title` is required by the installer form and is the <title> of every
 * public page. The account's hostname is a poor title and the empty string is
 * not allowed, so this is a name an administrator is expected to change under
 * Settings -- stated plainly rather than guessed at.
 */
function omeka_panelalpha_site_title()
{
    $title = getenv('OMEKA_SITE_TITLE');

    return is_string($title) && $title !== '' ? $title : 'Omeka';
}
