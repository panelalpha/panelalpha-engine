<?php

/*
 * Creates LaraCollab's first admin from the login the engine generated
 * (`credentials:` in panelalpha.yaml; the app container reads
 * ~/.panelalpha/app-credentials.env), so /login has an account and it is not
 * the one .env.example publishes.
 *
 * Upstream's install is `php artisan migrate --seed`; the laravel recipe runs
 * migrate only, so the database had no roles and no user. Here, only while the
 * database has no user at all, upstream's own seeders run: DatabaseSeeder
 * (roles, permissions, labels, priorities, currencies, countries, then, as
 * "Seed development data?" is answered no, ProductionSeeder's admin and owner
 * company) when there are no roles yet, else ProductionSeeder alone. Its admin
 * comes from config('auth.admin.*'), which is ADMIN_EMAIL / ADMIN_PASSWORD
 * (admin@mail.com / password in .env.example); it is set to the generated
 * login first. The development data is not seeded: its seeders need Faker, a
 * dev dependency the build does not install.
 *
 * A database that has users is left alone, so a password changed in the app
 * stays changed; except that the published admin, if it is still there with
 * its published password (a database seeded the upstream way), gets the
 * generated login instead.
 */

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

const PUBLISHED_EMAIL = 'admin@mail.com';
const PUBLISHED_PASSWORD = 'password';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] laracollab: '.$message."\n");
}

$email = (string) getenv('LARACOLLAB_ADMIN_EMAIL');
$password = (string) getenv('LARACOLLAB_ADMIN_PASSWORD');
if ($email === '' || $password === '') {
    say('LARACOLLAB_ADMIN_EMAIL or LARACOLLAB_ADMIN_PASSWORD is not in the environment; no admin created');
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

config([
    'auth.admin.email' => $email,
    'auth.admin.password' => $password,
    'auth.admin.name' => (string) (config('auth.admin.name') ?: 'Admin'),
]);

$seeder = Role::query()->exists()
    ? 'Database\\Seeders\\ProductionSeeder'
    : 'Database\\Seeders\\DatabaseSeeder';
$status = $kernel->call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]);
if ($status !== 0) {
    say('db:seed --class='.$seeder.' failed: '.trim($kernel->output()));
    exit(1);
}
say('seeded with '.$seeder.'; created the admin '.$email);
