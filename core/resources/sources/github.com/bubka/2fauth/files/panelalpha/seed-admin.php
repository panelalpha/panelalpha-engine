<?php
// Seed the owner administrator and close open registration, using 2FAuth's own
// models and services -- no upstream source is patched. Runs inside the init
// service before the app ever serves, so the first-registered-user-becomes-admin
// window is never open. Idempotent: on a redeploy the admin already exists and
// this leaves it (and the owner's own later changes) untouched.

require '/srv/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require '/srv/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Facades\Settings;
use App\Models\User;

$email = getenv('SEED_ADMIN_EMAIL');
$pass  = getenv('SEED_ADMIN_PASSWORD');
$name  = getenv('SEED_ADMIN_NAME') ?: 'admin';

if (! $email || ! $pass) {
    fwrite(STDERR, "seed-admin: SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD missing\n");
    exit(1);
}

if (! User::where('email', $email)->exists()) {
    $user = new User();
    $user->name              = $name;
    $user->email             = $email;
    $user->password          = $pass;      // the User model's 'hashed' cast bcrypts on set
    $user->email_verified_at = now();
    $user->promoteToAdministrator();       // is_admin = true (same as the first-user promotion)
    $user->save();
    fwrite(STDOUT, sprintf("seed-admin: created administrator %s (id %d)\n", $email, $user->id));
} else {
    fwrite(STDOUT, sprintf("seed-admin: administrator %s already present, left unchanged\n", $email));
}

// Default is open registration (config/2fauth.php settings.disableRegistration
// = false, no env override exists). Persist the closed state to the options
// table on the volume; the owner can re-open it from the admin settings later.
Settings::set('disableRegistration', true);
fwrite(STDOUT, "seed-admin: disableRegistration = true persisted\n");
