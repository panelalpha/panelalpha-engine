<?php

/*
 * Creates Free PMO's first admin from the login the engine generated
 * (`credentials:` in panelalpha.yaml; the app container reads
 * ~/.panelalpha/app-credentials.env), so /login has an account to sign in with.
 *
 * Upstream's installer is the /app-install form (InstallationController): while
 * there is no user it stores the agency's name, website and email in
 * site_options and creates the admin with the admin and worker roles. Nothing
 * links to it (/ and every page send a visitor to /login), so nobody deploying
 * Free PMO found it. Here, only while the database has no user at all, the same
 * records are written: agency_name (the app's name) and agency_email (the
 * generated email), and the admin "Administrator" with the generated email and
 * password, as the installer makes them. The installer then sends to /login.
 *
 * A database that has users is left alone, so a password changed in the app
 * stays changed.
 *
 * This file lives in panelalpha/, outside the document root (public/).
 */

use App\Entities\Users\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function say(string $message): void
{
    fwrite(STDERR, '[panelalpha] free-pmo: '.$message."\n");
}

$email = (string) getenv('FREE_PMO_ADMIN_EMAIL');
$password = (string) getenv('FREE_PMO_ADMIN_PASSWORD');
if ($email === '' || $password === '') {
    say('FREE_PMO_ADMIN_EMAIL or FREE_PMO_ADMIN_PASSWORD is not in the environment; no admin created');
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (User::query()->exists()) {
    say('the database has users already; left alone');
    exit(0);
}

DB::transaction(function () use ($email, $password) {
    // InstallationController::store's site_options rows; the website is left
    // out, as the installer leaves it when the form's field is empty.
    if (! DB::table('site_options')->where('key', 'agency_name')->exists()) {
        DB::table('site_options')->insert([
            ['key' => 'agency_name', 'value' => (string) config('app.name')],
            ['key' => 'agency_email', 'value' => $email],
        ]);
    }

    $admin = User::create([
        'name' => 'Administrator',
        'email' => $email,
        'password' => bcrypt($password),
        'api_token' => Str::random(32),
    ]);
    $admin->assignRole('admin');
    $admin->assignRole('worker');
});
say('created the admin '.$email);
