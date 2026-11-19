<?php

/*
 * Creates Easy POS's first admin from the login the engine generated
 * (`credentials:` in panelalpha.yaml; the app container reads
 * ~/.panelalpha/app-credentials.env), so the panel at /admin has a login and
 * it is not upstream's published one.
 *
 * Upstream's own installer (InstallController, on the first visit to /) runs
 * `db:seed` only while there is no `settings` table, and the laravel recipe's
 * migrate has made that table before the site is reachable, so nothing seeded
 * the database and no one could sign in. Here, only while the database has no
 * user at all: the SettingsSeeder that installer would have run (currency
 * symbol, site name and email), then Filament's own `make:filament-user` with
 * the generated email and password instead of UserSeeder's published
 * admin@admin.com / pass@123. The demo products and customers are not seeded:
 * their seeders need Faker, a dev dependency the build does not install.
 *
 * A database that has users is left alone, so a password changed in the app
 * stays changed; except that upstream's published admin, if it is still there
 * with its published password (a database seeded the upstream way), gets the
 * generated login instead.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

const PUBLISHED_EMAIL = 'admin@admin.com';
const PUBLISHED_PASSWORD = 'pass@123';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] easy-pos: '.$message."\n");
}

$email = (string) getenv('EASY_POS_ADMIN_EMAIL');
$password = (string) getenv('EASY_POS_ADMIN_PASSWORD');
if ($email === '' || $password === '') {
    say('EASY_POS_ADMIN_EMAIL or EASY_POS_ADMIN_PASSWORD is not in the environment; no admin created');
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (User::query()->exists()) {
    $published = User::query()->where('email', PUBLISHED_EMAIL)->first();
    if ($published !== null && Hash::check(PUBLISHED_PASSWORD, (string) $published->password)) {
        $published->forceFill(['email' => $email, 'password' => Hash::make($password)])->save();
        say('the published admin '.PUBLISHED_EMAIL.' now has the generated login');
    } else {
        say('the database has users already; left alone');
    }
    exit(0);
}

$status = $kernel->call('db:seed', ['--class' => 'Database\\Seeders\\SettingsSeeder', '--force' => true]);
if ($status !== 0) {
    say('db:seed --class=SettingsSeeder failed: '.trim($kernel->output()));
    exit(1);
}

$status = $kernel->call('make:filament-user', [
    '--name' => 'Admin User',
    '--email' => $email,
    '--password' => $password,
]);
if ($status !== 0) {
    say('make:filament-user failed: '.trim($kernel->output()));
    exit(1);
}
say('created the admin '.$email);
