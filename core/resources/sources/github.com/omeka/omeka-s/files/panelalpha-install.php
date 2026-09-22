<?php
/**
 * Installs Omeka S, or migrates it, from the command line.
 *
 * Omeka S has no installer CLI: InstallController puts a form at /install,
 * MvcListeners::redirectToInstallation() sends every other route to it, and
 * whoever loads it first becomes the global administrator. On a hosting
 * account that form must never be the thing a visitor meets, so this runs from
 * the container's install stage, before Apache binds.
 *
 * It is the controller's own work, without the browser: the same
 * `Omeka\Installer` service with the same eight tasks registered by
 * InstallerFactory, and the same two registerVars() calls InstallController
 * makes out of the validated form. Nothing here reimplements a task.
 *
 * On an account that is already installed it takes the other branch --
 * Omeka\MigrationManager::upgrade() and the version setting, which is what
 * MigrateController does when an administrator posts the /migrate form -- so
 * the upgrade stage carries a code change into the schema rather than leaving
 * the site on a page nobody is logged in to see.
 *
 * Booting the application in CLI is something Omeka supports deliberately:
 * MvcListeners::bootstrapSession() returns early on PHP_SAPI === 'cli', and
 * AuthenticationServiceFactory swaps in a NonPersistent storage whenever
 * Status says the application is not installed.
 */

// This file lives in the document root -- Omeka S serves from its own root --
// so it states its own terms as well. The generated vhost denies `panelalpha[-.]`
// and .htaccess rewrites a .php path that is not the front controller into
// index.php, which makes three independent answers to the same question.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/bootstrap.php';

$passwordFile = OMEKA_PATH . '/.panelalpha-admin-password';
$adminEmail = getenv('OMEKA_ADMIN_EMAIL');
if (!is_string($adminEmail) || $adminEmail === '') {
    // A hook is told no account address and no domain, so an address that is
    // stable and obviously a placeholder beats one that looks real. The
    // password is the secret, not the address; an administrator changes this
    // in the admin's own user form.
    $adminEmail = 'admin@example.com';
}

$title = getenv('OMEKA_INSTALLATION_TITLE');
if (!is_string($title) || $title === '') {
    $title = 'Omeka S';
}

$application = Omeka\Mvc\Application::init(
    require OMEKA_PATH . '/application/config/application.config.php'
);
$services = $application->getServiceManager();
$status = $services->get('Omeka\Status');

if ($status->isInstalled()) {
    // ModuleManagerFactory decided this by asking for the `module` table, so
    // it is a fact about the database rather than about a marker file the next
    // rebuild would wipe.
    if ($status->needsMigration()) {
        $services->get('Omeka\MigrationManager')->upgrade();
        echo "[omeka-s] ran the pending database migrations\n";
    }
    if ($status->needsVersionUpdate()) {
        $services->get('Omeka\Settings')->set('version', $status->getVersion());
        echo '[omeka-s] recorded version ' . $status->getVersion() . "\n";
    }
    echo "[omeka-s] already installed; nothing else to do\n";
    exit(0);
}

if (!is_readable($passwordFile)) {
    fwrite(STDERR, "[omeka-s] {$passwordFile} is missing; hooks/prepare.sh writes it\n");
    exit(1);
}
$password = trim((string) file_get_contents($passwordFile));
if ($password === '') {
    fwrite(STDERR, "[omeka-s] the generated administrator password is empty\n");
    exit(1);
}

$installer = $services->get('Omeka\Installer');

// The shape InstallationForm produces, because CreateFirstUserTask reads
// $vars['name'], $vars['email'] and $vars['password-confirm']['password']
// straight out of it -- `password-confirm` is the PasswordConfirm element's
// own name, not a field this script invented.
$installer->registerVars('Omeka\Installation\Task\CreateFirstUserTask', [
    'name' => 'admin',
    'email' => $adminEmail,
    'password-confirm' => ['password' => $password],
]);
$installer->registerVars('Omeka\Installation\Task\AddDefaultSettingsTask', [
    'administrator_email' => $adminEmail,
    'installation_title' => $title,
    // The container has no timezone of its own and Omeka stores this as a
    // setting an administrator can change; UTC is what the form defaults to
    // when php.ini does not say.
    'time_zone' => 'UTC',
    // Empty means "no forced locale": the translator keeps the configured
    // en_US and a user can pick their own. The form's input filter allows it.
    'locale' => '',
]);

if (!$installer->install()) {
    fwrite(STDERR, "[omeka-s] installation failed:\n");
    foreach ($installer->getErrors() as $error) {
        // An error is a string or an Omeka\Stdlib\Message, which is a
        // sprintf template plus its arguments and stringifies to the
        // untranslated sentence.
        fwrite(STDERR, '  - ' . (string) $error . "\n");
    }
    exit(1);
}

echo "[omeka-s] installed; the administrator is {$adminEmail}, password in .panelalpha-admin-password\n";
