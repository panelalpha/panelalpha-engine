<?php

/*
 * Gives Heimdall's seeded admin account a password, once, while it still has
 * none.
 *
 * database/seeders/UsersSeeder.php creates user 1 as `admin` with
 * `password = null`, and App\Http\Middleware\CheckAllowed reads that literally:
 *
 *     if (empty($current_user->password)) { return $next($request); }
 *
 * — every route, /settings and /users included, served to anyone who asks. That
 * is the right default for the LAN dashboard Heimdall was written to be and the
 * wrong one for an account the engine has just given a public HTTPS address, so
 * the password is set here and written to a file the operator can read.
 *
 * Guarded on the password still being null rather than on a first-boot marker:
 * re-running this must never reset a password somebody has since chosen in the
 * web interface. Rotate what upstream shipped, never what somebody chose.
 *
 * Plain PDO rather than the framework. Booting Laravel here would run
 * AppServiceProvider::boot() a fourth time in one deploy, and each of those
 * makes a network request for the supported-apps list; the two columns this
 * needs (`users.id`, `users.password`) have been in
 * 2018_10_12_122907_create_users_table.php since 2018 and a `$2y$` bcrypt hash
 * is exactly what Illuminate's BcryptHasher::check() verifies with
 * password_verify(). If either stops being true, this file is where it breaks.
 *
 * Never fatal: every unexpected state exits 0 with a line saying which, because
 * a site that would otherwise serve must not be held back by this step.
 */

const CREDENTIALS_FILE = '.panelalpha-admin-password';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] heimdall: ' . $message . "\n");
}

$projectDir = dirname(__DIR__);

// Heimdall's config/database.php wraps DB_DATABASE in database_path() whatever
// it holds, so the file is always under database/ -- resolved the same way here
// rather than guessed.
$connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
if ($connection !== 'sqlite') {
    say("the database is {$connection}, not sqlite; leaving the admin account alone");
    exit(0);
}

$name = (string) (getenv('DB_DATABASE') ?: 'app.sqlite');
if ($name === ':memory:') {
    say('the database is in memory; leaving the admin account alone');
    exit(0);
}

$databasePath = $projectDir . '/database/' . $name;
if (!is_file($databasePath)) {
    say("no database at {$databasePath} yet; leaving the admin account alone");
    exit(0);
}

try {
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $table = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($table === false) {
        say('the users table does not exist yet; leaving the admin account alone');
        exit(0);
    }

    $row = $pdo->query('SELECT id, username, password FROM users WHERE id = 1')->fetch();
    if ($row === false) {
        say('there is no user 1 yet; leaving the admin account alone');
        exit(0);
    }

    if (($row['password'] ?? null) !== null && (string) $row['password'] !== '') {
        say('the admin account already has a password; left alone');
        exit(0);
    }

    // No characters that need quoting when pasted into a shell or a form.
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    // Written before the update, so there is never a password in the database
    // that nothing recorded. BCRYPT_ROUNDS is Heimdall's own .env.example value
    // and what Illuminate's bcrypt driver is configured with here; a cost
    // mismatch would only make Laravel rehash on the first login anyway.
    $rounds = (int) (getenv('BCRYPT_ROUNDS') ?: 12);
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => max(4, min(31, $rounds))]);

    $credentials = $projectDir . '/' . CREDENTIALS_FILE;
    $written = @file_put_contents(
        $credentials,
        "# Written by PanelAlpha on the first deploy. Heimdall's seeded admin\n"
        . "# account had no password, which left every page open to the internet.\n"
        . 'HEIMDALL_ADMIN_USER=' . (string) $row['username'] . "\n"
        . 'HEIMDALL_ADMIN_PASSWORD=' . $password . "\n"
    );
    if ($written === false) {
        say('could not write ' . CREDENTIALS_FILE . '; leaving the admin account alone');
        exit(0);
    }
    @chmod($credentials, 0600);

    // `password IS NULL` again in the statement itself: between the read above
    // and here is the only window in which somebody else could have set one.
    $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = 1 AND password IS NULL');
    $update->execute([$hash]);

    if ($update->rowCount() === 0) {
        @unlink($credentials);
        say('the admin account gained a password while this ran; left alone');
        exit(0);
    }

    say('the admin account had no password, so every page was public. Set one; it is in ~/project/' . CREDENTIALS_FILE);
} catch (Throwable $e) {
    say('could not set the admin password (' . $e->getMessage() . '); leaving the account as it is');
    exit(0);
}
