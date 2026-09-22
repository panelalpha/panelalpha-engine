<?php

/*
 * Finishes the install Plainpad's own web installer would have done, and takes
 * the published admin password out of circulation.
 *
 * Runs inside the container in the install and upgrade stages, after the
 * laravel manifest's `migrate`. Two jobs, both idempotent:
 *
 *  1. Seed. `migrate` creates the four tables and stops there; every row
 *     Plainpad needs to run -- the admin account and the nine `settings`
 *     rows the SPA reads before it renders anything -- comes from
 *     DatabaseSeeder, which upstream only ever runs through
 *     ApplicationController::install() (POST /api.php/v1, no authentication,
 *     `migrate:fresh --seed`). That endpoint refuses once a `migrations` table
 *     exists, so after the engine's own migrate it can never run: without this
 *     the application answers 200 with no account to sign in to.
 *     Guarded on the users table being empty, so a redeploy over an existing
 *     database never seeds a second admin.
 *
 *  2. The password. UsersSeeder sets `12345678`, which is in the repository's
 *     README, in the seeder's own echo and in every copy of Plainpad ever
 *     deployed. hooks/prepare.sh generated one for this account and left its
 *     bcrypt hash in .panelalpha-admin.hash; it is put on the account here.
 *     Replaced only while the stored hash still verifies `12345678`: a password
 *     the operator has since chosen is never reset under them. Rotate what
 *     upstream shipped, never what somebody chose.
 *
 * Plain PDO rather than the framework, for the same reason Heimdall's script
 * is: booting Laravel a second time in one stage to read two columns is not
 * worth it. `users.id`, `users.email` and `users.password` are
 * 2019_11_19_081328_create_users_table.php's, and a $2y$ bcrypt hash is what
 * Illuminate\Hashing\BcryptHasher::check() verifies with password_verify().
 *
 * Exit codes: 1 only when seeding was needed and failed, because an
 * application with no account is a broken deploy that should say so. Every
 * other unexpected state exits 0 -- a site that would otherwise serve is not
 * held back by this.
 */

const HASH_FILE = '.panelalpha-admin.hash';

const KEY_FILE = '.panelalpha-app-key';

const SEEDED_PASSWORD = '12345678';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] plainpad: ' . $message . "\n");
}

/** The application root: this file lives in <root>/panelalpha/. */
$root = dirname(__DIR__);

// -----------------------------------------------------------------------------
// 0. APP_KEY
// -----------------------------------------------------------------------------
// server/.env is re-created from server/.env.example by every deploy, and a
// nested .env.example is copied verbatim -- placeholder and all -- so the file
// says `APP_KEY={KEY}`. `key:generate` repairs that on the first deploy only,
// because it is an install-stage command; the second deploy has a fresh
// `{KEY}` and nothing to replace it. hooks/prepare.sh keeps one key in
// ~/.panelalpha and leaves a copy here, and it is put into .env on every stage
// this runs in, so the key is the same one from the first boot onwards.
//
// Before `optimize`, which is a start-stage command and is what compiles .env
// into the config cache the running application reads.
$keyPath = $root . '/' . KEY_FILE;
$envPath = $root . '/.env';
$key = is_file($keyPath) ? trim((string) @file_get_contents($keyPath)) : '';
if (preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', $key) === 1 && is_file($envPath)) {
    $env = (string) @file_get_contents($envPath);
    if ($env !== '' && !str_contains($env, 'APP_KEY=' . $key)) {
        $replaced = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $env, 1, $count);
        if ($count === 1 && is_string($replaced) && @file_put_contents($envPath, $replaced) !== false) {
            say('put this account\'s APP_KEY into .env');
        } else {
            say('could not write APP_KEY into .env; the application keeps whatever is there');
        }
    }
} elseif ($key !== '') {
    say('the stored APP_KEY is not a 32-byte base64 key; leaving .env alone');
}

/**
 * The database the application is pointed at, as PDO.
 *
 * Read from the environment rather than from .env: compose sets DB_* under
 * `environment:`, and Laravel's Dotenv is immutable, so the environment is
 * what the application itself will use.
 */
function connect(string $root): ?PDO
{
    $connection = (string) (getenv('DB_CONNECTION') ?: 'mysql');

    if ($connection === 'sqlite') {
        $database = (string) (getenv('DB_DATABASE') ?: $root . '/database/database.sqlite');
        if ($database === ':memory:' || !is_file($database)) {
            say("no sqlite database at {$database}; leaving the application alone");

            return null;
        }

        return new PDO('sqlite:' . $database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    if ($connection !== 'mysql' && $connection !== 'mariadb') {
        say("the database connection is {$connection}, which this script does not speak; leaving the application alone");

        return null;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string) (getenv('DB_HOST') ?: '127.0.0.1'),
        (string) (getenv('DB_PORT') ?: '3306'),
        (string) getenv('DB_DATABASE')
    );

    return new PDO($dsn, (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

try {
    $pdo = connect($root);
} catch (Throwable $e) {
    say('could not reach the database (' . $e->getMessage() . '); leaving the application alone');
    exit(0);
}

if ($pdo === null) {
    exit(0);
}

// -----------------------------------------------------------------------------
// 1. Seed, once
// -----------------------------------------------------------------------------
try {
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    say('the users table is not readable (' . $e->getMessage() . '); did migrate run?');
    exit(0);
}

if ($users === 0) {
    say('the database has no users; running the application\'s own seeders');
    $output = [];
    $status = 0;
    // Upstream's seeders rather than a copy of their INSERTs: the nine
    // settings rows are SettingsSeeder's to define and it is the file that
    // changes when a setting is added.
    exec('php ' . escapeshellarg($root . '/artisan') . ' db:seed --force --no-interaction 2>&1', $output, $status);
    foreach ($output as $line) {
        say('db:seed: ' . $line);
    }
    if ($status !== 0) {
        say('seeding failed; the application has no account to sign in with');
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// 2. Take the published password out of circulation
// -----------------------------------------------------------------------------
//
// The hash file is kept, not consumed. It is the only thing in the container
// that knows this account's password, and a re-seed can happen at any point in
// the life of a deploy -- the upgrade stage runs on every container start, so
// an operator who drops the database and restarts gets a fresh UsersSeeder
// row. With the file deleted after first use, that row would keep the
// published `12345678` on a public site until the next redeploy. It holds a
// bcrypt hash rather than a password, it is 0600, and it sits above the
// document root; .env beside it already carries more.
$hashPath = $root . '/' . HASH_FILE;
if (!is_file($hashPath)) {
    say('no generated password on hand; leaving the admin account as it is');
    exit(0);
}

$hash = trim((string) @file_get_contents($hashPath));
if (!str_starts_with($hash, '$2y$')) {
    say('the generated hash is not a bcrypt hash; leaving the admin account alone');
    exit(0);
}

try {
    $statement = $pdo->prepare('SELECT id, password FROM users WHERE email = ? LIMIT 1');
    $statement->execute(['admin@example.org']);
    $admin = $statement->fetch();

    if ($admin === false) {
        say('there is no admin@example.org account; leaving the passwords alone');
        exit(0);
    }

    if (!password_verify(SEEDED_PASSWORD, (string) $admin['password'])) {
        say('the admin account no longer has the seeded password; left alone');
        exit(0);
    }

    // The WHERE repeats the row's own hash, so the update cannot land on a
    // password somebody chose in the window since the read above.
    $update = $pdo->prepare('UPDATE users SET password = ?, updated_at = ? WHERE id = ? AND password = ?');
    $update->execute([$hash, date('Y-m-d H:i:s'), $admin['id'], $admin['password']]);

    if ($update->rowCount() === 0) {
        say('the admin password changed while this ran; left alone');
        exit(0);
    }

    say('replaced the seeded admin password; this account\'s is in ~/.panelalpha/plainpad-admin');
} catch (Throwable $e) {
    say('could not set the admin password (' . $e->getMessage() . '); leaving the account as it is');
}

exit(0);
