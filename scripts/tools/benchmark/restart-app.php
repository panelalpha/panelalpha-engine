<?php
/**
 * Time a container boot of an already-built app: compose down, compose up,
 * then poll until the app answers on its published port.
 *
 * This is the number a restart or a host reboot costs a tenant, and it is the
 * only one of the three that involves no build at all.
 *
 * usage: restart-app.php <username>
 */
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$username = $argv[1] ?? null;
if ($username === null) {
    fwrite(STDERR, "usage: restart-app.php <username>\n");
    exit(2);
}

$user = App\Models\User::where('username', $username)->firstOrFail();
$project = $user->project();
$runtime = $project->runtime();
if (!$runtime instanceof App\System\Project\Dind) {
    fwrite(STDERR, "'{$username}' does not run on the dind project driver\n");
    exit(2);
}

$started = microtime(true);
$project->down();
$project->up();
// Poll hard with no delay: this measures how long the app takes to answer,
// not how patient the probe is. A one-second delay between attempts was
// reporting 73s for an nginx container that serves in under two.
$report = $runtime->appHealth()->check(2, 60, 0);

echo json_encode([
    'seconds' => round(microtime(true) - $started, 1),
    'healthy' => $report['healthy'] ?? null,
], JSON_UNESCAPED_SLASHES), "\n";
