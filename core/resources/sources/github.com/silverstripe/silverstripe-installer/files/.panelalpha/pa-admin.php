<?php

/**
 * Create the account's administrator, or set its password.
 *
 * Boots the kernel the way vendor/silverstripe/framework/bin/sake does. A
 * BuildTask would be the idiomatic route, but it would have to live in the
 * customer's app/src/ and would then be in the class manifest and the task
 * list forever; this is the same work from outside the tree, in a
 * dot-directory Apache denies and that sits above the document root anyway.
 *
 * Deliberately not SS_DEFAULT_ADMIN_USERNAME/PASSWORD: those are compared on
 * every login attempt for as long as they are set, and DefaultAdminService's
 * own findOrCreateDefaultAdmin() writes a Member with no password of its own
 * ("this user won't be able to login until a password is set"), so unsetting
 * them after the install would leave nobody able to log in at all.
 */

require_once __DIR__ . '/../vendor/silverstripe/framework/src/includes/autoload.php';

use SilverStripe\Core\CoreKernel;
use SilverStripe\Security\DefaultAdminService;

$password = getenv('PA_SS_ADMIN_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "[panelalpha] pa-admin: PA_SS_ADMIN_PASSWORD is not in the environment; the "
        . "compose override that carries ~/.panelalpha/silverstripe/admin.env is missing\n");
    exit(1);
}

// The prepare hook cannot know the account's domain -- nothing in the account
// names it that early -- so the login is settled here, where the engine has
// already put it in the environment.
$email = getenv('PA_SS_ADMIN_EMAIL');
if (!is_string($email) || $email === '') {
    $host = getenv('SERVERNAME');
    $email = 'admin@' . (is_string($host) && $host !== '' ? $host : 'localhost');
}

$kernel = new CoreKernel(BASE_PATH);
$kernel->boot();
$admin = DefaultAdminService::singleton()->findOrCreateAdmin($email, 'Administrator');
$result = $admin->changePassword($password);
$kernel->shutdown();

if (!$result->isValid()) {
    fwrite(STDERR, '[panelalpha] pa-admin: ' . json_encode($result->getMessages()) . "\n");
    exit(1);
}

fwrite(STDERR, "[panelalpha] pa-admin: administrator {$email} is ready; its password is in "
    . "~/.panelalpha/silverstripe/admin.env\n");
