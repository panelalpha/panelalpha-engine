<?php

/*
 * Creates Hisabi's user from the login the engine generated (`credentials:` in
 * panelalpha.yaml; the app container reads ~/.panelalpha/app-credentials.env),
 * so /login has an account to sign in with.
 *
 * Hisabi has no sign-up; upstream makes the user with `php artisan
 * hisabi:install` (App\Console\Commands\InstallCommand), which asks for a name,
 * an email and a password on a terminal, offers db:seed (the default categories
 * and brands, "yes" preselected) and creates the user with a bcrypt hash. Here,
 * only while the database has no user at all, the same with its default
 * answers: db:seed while there is no category yet, then the user "Admin" with
 * the generated email and password.
 *
 * A database that has a user is left alone, so a password changed in the app
 * stays changed.
 *
 * This file lives in panelalpha/, outside the document root (public/).
 */

use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] hisabi: '.$message."\n");
}

$email = (string) getenv('HISABI_ADMIN_EMAIL');
$password = (string) getenv('HISABI_ADMIN_PASSWORD');
if ($email === '' || $password === '') {
    say('HISABI_ADMIN_EMAIL or HISABI_ADMIN_PASSWORD is not in the environment; no user created');
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (User::query()->exists()) {
    say('the database has a user already; left alone');
    exit(0);
}

if (! Category::query()->exists()) {
    $status = $kernel->call('db:seed', ['--force' => true]);
    if ($status !== 0) {
        say('db:seed failed: '.trim($kernel->output()));
        exit(1);
    }
}

User::create(['name' => 'Admin', 'email' => $email, 'password' => bcrypt($password)]);
say('created the user '.$email);
