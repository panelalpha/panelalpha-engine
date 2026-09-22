<?php

/**
 * Written by PanelAlpha. Two jobs, both on the install stage, both before
 * Apache binds.
 *
 * 1. Put the account's persistent APP_KEY into .env.
 *
 *    It is generated once per account by the prepare hook into
 *    ~/.panelalpha/xbackbone/app.key and is deliberately not written into the
 *    checkout's .env there: the engine copies whatever .env the prepare hook
 *    leaves into a world-readable .env.default (engine#173), and an
 *    application key has no business in that file. It is put here instead,
 *    after that copy has been taken.
 *
 *    The key is persistent rather than regenerated because a redeploy
 *    re-clones ~/project while the database survives it in /data: a new key
 *    would invalidate every session and every encrypted value already in it.
 *
 * 2. Run XBackBone's own installer without a browser.
 *
 *    XBackBone ships a guided web installer, and until it has been completed
 *    XBB\Installer\Http\Middleware\EnsureInstalled redirects every request to
 *    /install, where whoever arrives first chooses the database, the storage
 *    backend and the administrator account. On an account that has just been
 *    given a public HTTPS name that is first-visitor-wins.
 *
 *    XBB\Installer\Actions\FinalizeInstallation is the action the wizard's
 *    last step calls, with the same payload, so nothing here reimplements or
 *    bypasses it: it probes the database, writes the .env, migrates, creates
 *    the administrator, writes storage/installed -- which is what closes the
 *    installer -- and caches the config.
 *
 * Idempotent. The key is only written when .env does not already carry one,
 * and the install returns early once the application reports itself installed;
 * FinalizeInstallation itself reuses an existing administrator with the same
 * address rather than creating a second one, so the upgrade stage re-running
 * this over a database that survived the redeploy is a no-op.
 */

$base = '/app';

// The account's persistent directory: ~/.panelalpha/xbackbone, bind-mounted
// here by the compose override.
$dataDir = getenv('XBB_DATA_DIR') ?: '/data';
$envPath = $base . '/.env';

// ---------------------------------------------------------------- APP_KEY

// Plain file work, before the framework is loaded: the key has to be in place
// before anything reads the configuration.
$env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
if (preg_match('/^APP_KEY=\S/m', $env) !== 1) {
    $keyPath = $dataDir . '/app.key';
    if (!is_file($keyPath)) {
        fwrite(STDERR, "[panelalpha] no application key at {$keyPath}\n");
        exit(1);
    }
    $key = trim((string) file_get_contents($keyPath));
    $env = preg_match('/^APP_KEY=.*$/m', $env) === 1
        ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $env, 1)
        : rtrim($env, "\n") . "\nAPP_KEY=" . $key . "\n";
    file_put_contents($envPath, $env);
    @chmod($envPath, 0600);
}

require $base . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use XBB\Installer\Actions\FinalizeInstallation;
use XBB\Installer\Support\InstallationState;

// ---------------------------------------------------------------- install

if (InstallationState::isInstalled()) {
    fwrite(STDERR, "[panelalpha] XBackBone is already installed; nothing to do\n");
    exit(0);
}

$credentialsPath = $dataDir . '/admin-credentials';

if (!is_file($credentialsPath)) {
    fwrite(STDERR, "[panelalpha] no credentials file at {$credentialsPath}; leaving the installer open\n");
    exit(1);
}

$credentials = parse_ini_file($credentialsPath, false, INI_SCANNER_RAW) ?: [];
$email = trim((string) ($credentials['XBACKBONE_ADMIN_EMAIL'] ?? ''));
$password = (string) ($credentials['XBACKBONE_ADMIN_PASSWORD'] ?? '');

if ($password === '') {
    fwrite(STDERR, "[panelalpha] credentials file carries no password; leaving the installer open\n");
    exit(1);
}

$appUrl = rtrim((string) (getenv('APP_URL') ?: config('app.url')), '/');
$host = parse_url($appUrl, PHP_URL_HOST) ?: '';

// The prepare hook cannot know the account's address -- the engine puts it in
// the container environment, which is here. The resolved address is written
// back so the credentials file names the account the customer will sign in to.
if ($host !== '' && ($email === '' || $email === 'admin@localhost')) {
    $email = 'admin@' . $host;
    file_put_contents(
        $credentialsPath,
        preg_replace(
            '/^XBACKBONE_ADMIN_EMAIL=.*$/m',
            'XBACKBONE_ADMIN_EMAIL=' . $email,
            (string) file_get_contents($credentialsPath),
            1
        )
    );
}

$payload = [
    'appUrl' => $appUrl !== '' ? $appUrl : 'http://localhost',
    'database' => [
        'driver' => 'sqlite',
        // Outside the checkout on purpose: a redeploy re-clones ~/project and
        // would otherwise take the database with it.
        'sqlitePath' => $dataDir . '/xbb.db',
    ],
    // The local driver, whose root is storage_path('app') -- a symlink into
    // the same persistent directory, written by the prepare hook.
    // config/filesystems.php hard-codes that root and the config directory
    // belongs to the core package, so the symlink is the only lever.
    'storage' => ['driver' => 'local'],
    'admin' => [
        'name' => 'Administrator',
        'email' => $email,
        'password' => $password,
    ],
    // Synchronous: previews are generated by a queued job and this account
    // runs no worker, so `database` here would mean uploads never get a
    // thumbnail. An operator who adds a worker can switch it in .env.
    'queue' => ['sync' => true],
    'import' => null,
];

try {
    $admin = app(FinalizeInstallation::class)($payload);
} catch (\Throwable $e) {
    fwrite(STDERR, '[panelalpha] XBackBone install failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDERR, "[panelalpha] XBackBone installed; administrator {$admin->email}\n");
exit(0);
