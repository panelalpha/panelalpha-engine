<?php
/**
 * Deploy one fixture through the same controller the API uses, and print the
 * frozen deploy details as JSON.
 *
 * Runs inside the core container. Driving the controller rather than the HTTP
 * API keeps the benchmark independent of token provisioning, which is blocked
 * on some hosts.
 *
 * usage: deploy-fixture.php <username> <repo> [branch]
 */
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\UserController;
use App\Http\Requests\UserStoreRequest;
use Illuminate\Http\Request;

[, $username, $repo] = array_pad($argv, 3, null);
$branch = $argv[3] ?? null;
if ($username === null || $repo === null) {
    fwrite(STDERR, "usage: deploy-fixture.php <username> <repo> [branch]\n");
    exit(2);
}

$payload = array_filter([
    'username' => $username,
    'email' => 'benchmark@example.invalid',
    'git_repo' => $repo,
    'git_branch' => $branch,
    'domain' => $username . '.benchmark.invalid',
]);

$base = Request::create('/api/users', 'POST', $payload);
$app->instance('request', $base);
$request = UserStoreRequest::createFrom($base);
$request->setContainer($app);

$started = microtime(true);
$error = null;
try {
    $request->validateResolved();
    $app->make(UserController::class)->store($request);
} catch (Throwable $e) {
    $error = substr(str_replace("\n", ' ', $e->getMessage()), 0, 300);
}

$user = App\Models\User::where('username', $username)->first();
$details = $user?->getDetails() ?? [];

echo json_encode([
    'username' => $username,
    'seconds' => round(microtime(true) - $started, 1),
    'strategy' => $details['deploy_strategy'] ?? null,
    'runtime' => $details['deploy_runtime'] ?? null,
    'port' => $details['app_port'] ?? null,
    'status' => $details['deployment_status'] ?? ($error === null ? null : 'failed'),
    'error' => $error,
], JSON_UNESCAPED_SLASHES), "\n";
