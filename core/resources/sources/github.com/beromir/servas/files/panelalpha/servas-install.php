<?php

/**
 * Written by PanelAlpha. Runs inside the container on the install and upgrade
 * stages, after the laravel manifest's key:generate, storage:link and migrate,
 * and before `optimize` compiles .env into the config cache the running
 * application reads.
 *
 * Three jobs, all idempotent, none of which a Servas checkout can do for
 * itself.
 *
 *  1. The application key. hooks/prepare.sh keeps one per installation in
 *     ~/.panelalpha/servas/app.key -- outside ~/project, which a redeploy
 *     re-clones, and outside .env, which the engine copies to a
 *     world-readable .env.default (engine#173). It is written here because
 *     this is the first moment after both of those: after that copy, and
 *     after `key:generate`, which is an install-stage command and would
 *     otherwise leave the first deploy running on a key the second deploy
 *     does not have. Every session and every Fortify two-factor secret in a
 *     database that outlives the checkout depends on it being the same key.
 *
 *  2. APP_URL. prepare.sh runs on the account and does not know the address
 *     the engine is about to give it; the container does, in its environment.
 *
 *  3. The account. Servas ships no installer, no seeded user and no admin
 *     role -- its registration form is the only way anybody ever gets in, and
 *     config/fortify.php enables it by default. On a public HTTPS address that
 *     is first-visitor-wins, so prepare.sh closed registration and generated
 *     the credentials, and the account is created here. Guarded on the users
 *     table being empty, so a redeploy over a database that survived it never
 *     creates a second one and never resets a password somebody chose.
 *
 * Exit codes: 1 only when the installation would be left with no way in --
 * no key, or an empty database this could not seed an account into. Anything
 * else that is merely unexpected exits 0 rather than holding back a site that
 * would otherwise serve.
 */

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$base = '/app';

// ~/.panelalpha/servas, bind-mounted here by the compose override.
$dataDir = getenv('SERVAS_DATA_DIR') ?: '/data';
$envPath = $base . '/.env';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] servas: ' . $message . "\n");
}

/**
 * Set one key in a .env file, replacing the first occurrence or appending.
 */
function envSet(string $path, string $key, string $value): bool
{
    $env = is_file($path) ? (string) @file_get_contents($path) : '';
    $line = $key . '=' . $value;
    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env) === 1) {
        $env = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $env, 1);
    } else {
        $env = rtrim($env, "\n") . "\n" . $line . "\n";
    }
    if (!is_string($env) || @file_put_contents($path, $env) === false) {
        return false;
    }
    @chmod($path, 0600);

    return true;
}

// ------------------------------------------------------------------ APP_KEY

// Plain file work, before the framework is loaded: the key has to be in place
// before anything reads the configuration.
$keyPath = $dataDir . '/app.key';
$key = is_file($keyPath) ? trim((string) @file_get_contents($keyPath)) : '';

if (preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', $key) !== 1) {
    say("no usable application key at {$keyPath}");
    exit(1);
}

$env = is_file($envPath) ? (string) @file_get_contents($envPath) : '';
if (!str_contains($env, 'APP_KEY=' . $key)) {
    if (!envSet($envPath, 'APP_KEY', $key)) {
        say('could not write the application key into .env');
        exit(1);
    }
    say('put this installation\'s application key into .env');
}

// ------------------------------------------------------------------ APP_URL

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
$host = $appUrl !== '' ? (parse_url($appUrl, PHP_URL_HOST) ?: '') : '';
if ($appUrl !== '') {
    envSet($envPath, 'APP_URL', $appUrl);
}

// ------------------------------------------------------------------ account

require $base . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $users = User::query()->count();
} catch (\Throwable $e) {
    say('the users table is not readable (' . $e->getMessage() . '); did migrate run?');
    exit(1);
}

if ($users > 0) {
    say('the installation already has an account; nothing to do');
    exit(0);
}

$credentialsPath = $dataDir . '/credentials';
if (!is_file($credentialsPath)) {
    say("no credentials file at {$credentialsPath}; the installation has no way in");
    exit(1);
}

$credentials = parse_ini_file($credentialsPath, false, INI_SCANNER_RAW) ?: [];
$email = trim((string) ($credentials['SERVAS_EMAIL'] ?? ''));
$password = (string) ($credentials['SERVAS_PASSWORD'] ?? '');

if ($password === '') {
    say('the credentials file carries no password; the installation has no way in');
    exit(1);
}

// The address is the container's to know, and the credentials file is what the
// customer reads, so the resolved one is written back into it.
if ($host !== '' && ($email === '' || $email === 'admin@localhost')) {
    $email = 'admin@' . $host;
    $file = (string) @file_get_contents($credentialsPath);
    $replaced = preg_replace('/^SERVAS_EMAIL=.*$/m', 'SERVAS_EMAIL=' . $email, $file, 1);
    if (is_string($replaced)) {
        @file_put_contents($credentialsPath, $replaced);
        @chmod($credentialsPath, 0600);
    }
}

if ($email === '') {
    $email = 'admin@localhost';
}

try {
    // Hash::make rather than a cast: this model has none, and CreateNewUser --
    // the action the registration form calls -- hashes explicitly too, so this
    // creates exactly the row a registration would have.
    $user = new User();
    $user->name = 'Admin';
    $user->email = $email;
    $user->password = Hash::make($password);
    $user->save();
} catch (\Throwable $e) {
    say('could not create the account (' . $e->getMessage() . ')');
    exit(1);
}

say("created the account {$user->email}; its password is in ~/.panelalpha/servas/credentials");
exit(0);
