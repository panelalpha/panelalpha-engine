<?php

/*
 * Gives S-Cart's seeded administrator the login the engine generated, on an
 * install whose admin password is still one nobody chose.
 *
 * The engine generates the login (`credentials:` in panelalpha.yaml:
 * SCART_ADMIN_USER and SCART_ADMIN_PASSWORD, which the container gets from
 * ~/.panelalpha/app-credentials.env and GET /projects/{name}/app-credentials
 * returns). On a new install panelalpha/install.sh hands the password to
 * `gp247:install --admin-password`, GP247's own way, and this finds it set.
 * It is for installs that predate that, whose admin `admin` has:
 *
 *   - `admin`. GP247\Core\Database\Seeders\DataDefaultSeeder seeds a
 *     hardcoded bcrypt hash of it, and older gp247/core releases left it there
 *     and printed "User/password: admin/admin" to the deploy log;
 *   - the password an older version of this recipe generated and recorded in
 *     /panelalpha/admin-password (~/.panelalpha/scart/admin-password,
 *     `password: ...`), which nobody deploying it was told. That record is
 *     removed once the account has the engine's login;
 *   - a random password gp247:install generated itself (current releases,
 *     without --admin-password) and printed only to the deploy log. GP247
 *     sets it with a query that leaves `updated_at` empty, and every save
 *     through the admin panel (a password change included) fills it, so a row
 *     whose `updated_at` is still empty has never been touched by anyone.
 *
 * Any other password was chosen in the admin panel and is left alone, so a
 * restart or a redeploy never resets it. The account is renamed when
 * SCART_ADMIN_USER is not `admin`. The UPDATE repeats the old hash in its
 * WHERE clause, so a password changed while this runs is not overwritten.
 *
 * Boots the framework rather than speaking raw PDO: the connection is whatever
 * config/database.php resolves (a sidecar today, an operator's managed MySQL
 * tomorrow), the table prefix is GP247's own GP247_DB_PREFIX, and Hash::make()
 * is by definition the hasher the login form will check against -- all three
 * are things a hand-rolled PDO script would have to guess and would eventually
 * guess wrong.
 */

$root = dirname(__DIR__);

$login = (string) getenv('SCART_ADMIN_USER');
$password = (string) getenv('SCART_ADMIN_PASSWORD');
if ($login === '' || $password === '') {
    fwrite(STDERR, "[s-cart] SCART_ADMIN_USER or SCART_ADMIN_PASSWORD is not in the environment (~/.panelalpha/app-credentials.env); the admin login was not set\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Defined by CoreServiceProvider::register(), which has run by now; the env
// fallback is what that constant is built from and covers a boot order that
// changes under us.
$prefix = defined('GP247_DB_PREFIX') ? GP247_DB_PREFIX : (string) env('GP247_DB_PREFIX', 'gp247_');
$table = $prefix . 'admin_user';
// ~/.panelalpha/scart, mounted by the compose override; ~/project is wiped on redeploy.
$legacyFile = '/panelalpha/admin-password';

$legacy = '';
if (is_file($legacyFile)) {
    foreach (file($legacyFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'password: ')) {
            $legacy = substr($line, strlen('password: '));
        }
    }
}

try {
    $admin = \Illuminate\Support\Facades\DB::table($table)->where('username', 'admin')->first();
} catch (\Throwable $e) {
    fwrite(STDERR, "[s-cart] could not read {$table}: " . $e->getMessage() . "\n");
    exit(1);
}

if ($admin === null) {
    echo "[s-cart] no seeded `admin` account; nothing to set\n";
    exit(0);
}

$current = (string) ($admin->password ?? '');
if ($current !== '' && $admin->username === $login && password_verify($password, $current)) {
    if (is_file($legacyFile)) {
        @unlink($legacyFile);
    }
    echo "[s-cart] the admin account has the login the engine generated\n";
    exit(0);
}
$unchosen = $current === ''
    || password_verify('admin', $current)
    || ($legacy !== '' && password_verify($legacy, $current))
    || ($admin->updated_at ?? null) === null;
if (!$unchosen) {
    echo "[s-cart] the admin account has a password chosen in the panel; left alone\n";
    exit(0);
}

// The old hash in the WHERE clause: if anything else set a password between the
// read above and here, this updates nothing rather than overwriting it.
$updated = \Illuminate\Support\Facades\DB::table($table)
    ->where('id', $admin->id)
    ->where('password', $current)
    ->update([
        'username' => $login,
        'password' => \Illuminate\Support\Facades\Hash::make($password),
        'updated_at' => now(),
    ]);

if ($updated === 0) {
    echo "[s-cart] the admin password changed while this ran; left alone\n";
    exit(0);
}

// The older record names a password that no longer works.
if (is_file($legacyFile)) {
    @unlink($legacyFile);
}

echo "[s-cart] gave the admin account the login the engine generated ({$login})\n";
exit(0);
