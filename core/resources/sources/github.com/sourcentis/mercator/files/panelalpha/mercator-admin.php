<?php

/*
 * Gives Mercator's first admin the login the engine generated
 * (`credentials:` in panelalpha.yaml; the app container has
 * MERCATOR_ADMIN_EMAIL / MERCATOR_ADMIN_PASSWORD from app-credentials.env), so
 * /login has an account and it is not the one upstream publishes.
 *
 * The image's entrypoint runs migrate on every start but seeds only with
 * SEED_DATABASE=1 or USE_DEMO_DATA=1, so a new database had no roles and no
 * user. Here, only while the database has no user at all, upstream's own
 * DatabaseSeeder runs (perimeters, permissions, roles, their links, and the
 * admin admin@admin.com / `password` with the Admin role; no demo data unless
 * USE_DEMO_DATA is set), and that admin then gets the generated login and
 * email, through the User model as the app's own forms save them.
 *
 * A database that has users is left alone, so a password changed in the app
 * stays changed; except that the published admin, if it is still there with
 * its published password (a database seeded the upstream way), gets the
 * generated login instead.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

const PUBLISHED_LOGIN = 'admin@admin.com';
const PUBLISHED_PASSWORD = 'password';

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] mercator: '.$message."\n");
}

$email = (string) getenv('MERCATOR_ADMIN_EMAIL');
$password = (string) getenv('MERCATOR_ADMIN_PASSWORD');
if ($email === '' || $password === '') {
    say('MERCATOR_ADMIN_EMAIL or MERCATOR_ADMIN_PASSWORD is not in the environment; no admin set up');
    exit(1);
}

require '/var/www/mercator/vendor/autoload.php';
$app = require '/var/www/mercator/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$seeded = false;
if (! User::query()->exists()) {
    $status = $kernel->call('db:seed', ['--force' => true, '--no-interaction' => true]);
    if ($status !== 0) {
        say('db:seed failed: '.trim($kernel->output()));
        exit(1);
    }
    $seeded = true;
}

$published = User::query()->where('login', PUBLISHED_LOGIN)->first();
if ($published !== null && Hash::check(PUBLISHED_PASSWORD, (string) $published->password)) {
    $published->forceFill(['login' => $email, 'email' => $email, 'password' => $password])->save();
    say(($seeded ? 'seeded the database; ' : 'the published admin '.PUBLISHED_LOGIN.' was still there; ')
        .'the admin is now '.$email);
} else {
    say('the database has users already; left alone');
}
