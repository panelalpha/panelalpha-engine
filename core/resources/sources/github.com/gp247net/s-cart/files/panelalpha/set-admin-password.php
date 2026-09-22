<?php

/*
 * Replaces S-Cart's seeded admin credential, once, while it is still the one
 * upstream shipped.
 *
 * GP247\Core\Database\Seeders\DataDefaultSeeder creates the only administrator
 * with a hardcoded bcrypt hash:
 *
 *     public $adminUser     = 'admin';
 *     public $adminPassword = '$2y$10$JcmAHe5eUZ2rS0jU1GWr/.xhwCnh2RU13qwjTPcqfmtZXjZxcryPO';
 *
 * which is the password `admin` -- password_verify() says so -- and
 * GP247\Core\Commands\Install prints "User/password: admin/admin" to the
 * console when it finishes, so the deploy log says it too. That is a reasonable
 * default for the local evaluation install upstream documents, and the wrong
 * one for an account the engine has just given a public HTTPS address and a
 * certificate.
 *
 * Guarded on the password still verifying as `admin` rather than on a
 * first-boot marker: re-running this must never reset a password somebody has
 * since chosen in the admin panel. Rotate what upstream shipped, never what
 * somebody chose. The credentials file is written before the UPDATE, so there
 * is never a password in the database that nothing recorded, and the UPDATE
 * repeats the old hash in its WHERE clause to close the gap between the two.
 *
 * Boots the framework rather than speaking raw PDO: the connection is whatever
 * config/database.php resolves (a sidecar today, an operator's managed MySQL
 * tomorrow), the table prefix is GP247's own GP247_DB_PREFIX, and Hash::make()
 * is by definition the hasher the login form will check against -- all three
 * are things a hand-rolled PDO script would have to guess and would eventually
 * guess wrong.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Defined by CoreServiceProvider::register(), which has run by now; the env
// fallback is what that constant is built from and covers a boot order that
// changes under us.
$prefix = defined('GP247_DB_PREFIX') ? GP247_DB_PREFIX : (string) env('GP247_DB_PREFIX', 'gp247_');
$table = $prefix . 'admin_user';
$file = $root . '/.panelalpha-admin-password';

try {
    $admin = \Illuminate\Support\Facades\DB::table($table)->where('username', 'admin')->first();
} catch (\Throwable $e) {
    fwrite(STDERR, "[s-cart] could not read {$table}: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[s-cart] the admin account still has the password `admin` -- change it in the panel.\n");
    exit(0);
}

if ($admin === null) {
    echo "[s-cart] no seeded `admin` account; nothing to rotate\n";
    exit(0);
}

$current = (string) ($admin->password ?? '');
if ($current === '' || !password_verify('admin', $current)) {
    echo "[s-cart] the admin account no longer has the seeded password; left alone\n";
    exit(0);
}

// 24 hex characters. Hex rather than a mixed alphabet because this value is
// read back out of a file by a human and typed into a login form.
$password = bin2hex(random_bytes(12));

$written = @file_put_contents(
    $file,
    "S-Cart administrator, generated on first install by PanelAlpha.\n"
    . "Admin panel: /gp247_admin\n"
    . "username: admin\n"
    . "password: {$password}\n"
);
if ($written === false) {
    fwrite(STDERR, "[s-cart] could not write {$file}; refusing to change a password nothing would record.\n");
    fwrite(STDERR, "[s-cart] the admin account still has the password `admin` -- change it in the panel.\n");
    exit(0);
}
@chmod($file, 0600);

// The old hash in the WHERE clause: if anything else set a password between the
// read above and here, this updates nothing rather than overwriting it.
$updated = \Illuminate\Support\Facades\DB::table($table)
    ->where('username', 'admin')
    ->where('password', $current)
    ->update(['password' => \Illuminate\Support\Facades\Hash::make($password), 'updated_at' => now()]);

if ($updated === 0) {
    @unlink($file);
    echo "[s-cart] the admin password changed while this ran; left alone\n";
    exit(0);
}

echo "[s-cart] set a generated password for `admin`; it is in ~/project/.panelalpha-admin-password (0600)\n";
exit(0);
