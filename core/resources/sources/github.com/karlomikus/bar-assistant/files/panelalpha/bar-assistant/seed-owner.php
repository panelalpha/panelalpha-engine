<?php
// Seed the operator's owner account server-side. Registration over HTTP is
// closed (ALLOW_REGISTRATION=false), so this calls the app's own domain service
// directly -- the documented "add a user via artisan" path, configuration not
// repair. tinker is stripped by composer --no-dev, so bootstrap the console
// kernel over the optimized autoloader rather than going through tinker.
// Idempotent: UserService::register throws if the email already exists, which
// on a redeploy just means the owner is already seeded.

declare(strict_types=1);

$base = '/var/www/cocktails';
require $base . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = getenv('OWNER_EMAIL') ?: 'owner@bar-assistant.local';
$pass  = getenv('OWNER_PASSWORD') ?: '';
$name  = getenv('OWNER_NAME') ?: 'Owner';

if ($pass === '') {
    fwrite(STDERR, "[seed] OWNER_PASSWORD not set; refusing to seed\n");
    exit(0);
}

try {
    $app->make(\BarAssistant\Application\User\UserService::class)->register(
        new \BarAssistant\Application\User\DTO\RegisterUserRequest(
            name: $name,
            email: $email,
            passwordHash: \Illuminate\Support\Facades\Hash::make($pass),
            confirmAccount: true,
        )
    );
    fwrite(STDOUT, "[seed] owner created: {$email}\n");
} catch (\Throwable $e) {
    fwrite(STDOUT, "[seed] skipped: {$e->getMessage()}\n");
}

exit(0);
