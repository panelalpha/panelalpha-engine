#!/usr/bin/env php
<?php
/**
 * Standalone DinD deploy tester — uses the REAL PanelAlpha Engine code.
 *
 * Bootstraps Laravel from core/ so all real classes run unchanged.
 * The only override: TestSystem strips 'sudo'/'chown' from commands
 * and redirects /home/{user} file I/O to a test directory.
 *
 * Usage:
 *   php deploy.php <git-url> [--name=NAME] [--branch=BRANCH] [--timeout=SECONDS]
 *   php deploy.php <local-path> [--name=NAME]
 */

declare(strict_types=1);

// ── Bootstrap Laravel from core/ ──────────────────────────────────────────

// Timing helpers — total time is reported in the summary.
$phaseStart = microtime(true);
$phaseTimes = [];

function startPhase(string $name): void
{
    global $phaseStart, $phaseTimes;
    $phaseStart = microtime(true);
}

function endPhase(string $name): string
{
    global $phaseStart;
    $elapsed = microtime(true) - $phaseStart;
    return round($elapsed, 1) . 's';
}

// ── Bootstrap Laravel from core/ ──────────────────────────────────────────

$coreRoot = realpath(__DIR__ . '/../..') . '/core';
require $coreRoot . '/vendor/autoload.php';
$app = require $coreRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ── CLI args ──────────────────────────────────────────────────────────────

// Parsed by hand rather than with getopt(): getopt() stops at the first
// non-option argument, so `deploy.php <path> --timeout=180` silently dropped
// every flag after the path. All options are --key=value or bare boolean.
$options = [];
$positional = [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--')) {
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$key, $value] = explode('=', $body, 2);
            $options[$key] = $value;
        } else {
            $options[$body] = false;
        }
        continue;
    }
    $positional[] = $arg;
}

if (isset($options['help']) || count($positional) === 0) {
    fwrite(STDERR, "Usage: php deploy.php <git-url> [--name=NAME] [--branch=BRANCH] [--timeout=30]\n");
    fwrite(STDERR, "       php deploy.php <local-path> [--name=NAME]\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  --real-home        Put the account at /home/<container> instead of the\n");
    fwrite(STDERR, "                     disk cache dir. REQUIRED for the nginx-static and\n");
    fwrite(STDERR, "                     Nitro-standalone recipes: their host compile is gated\n");
    fwrite(STDERR, "                     by HostNodeBuild::isSafeProjectDir(), which accepts\n");
    fwrite(STDERR, "                     only /home/<user>/project. Create the dir first:\n");
    fwrite(STDERR, "                       sudo mkdir -p /home/dind-test-NAME \\\n");
    fwrite(STDERR, "                         && sudo chown \$(id -u):\$(id -g) /home/dind-test-NAME\n");
    fwrite(STDERR, "  --reuse-container  Redeploy into the container that is already running,\n");
    fwrite(STDERR, "                     preserving its BuildKit cache. This is the only way to\n");
    fwrite(STDERR, "                     measure a real WARM deploy; without it every run is cold.\n");
    fwrite(STDERR, "  --keep-cache       Keep the host build cache (node_modules / npm / pnpm /\n");
    fwrite(STDERR, "                     yarn / bun) between runs.\n");
    fwrite(STDERR, "  --seed-only        Create the account and run the GLOBAL SEED, then stop.\n");
    fwrite(STDERR, "                     Run this once, then deploy every app with\n");
    fwrite(STDERR, "                     --reuse-container so the seed is paid for once.\n");
    exit(1);
}

$source  = $positional[0];
$name    = $options['name'] ?? deriveName($source);
$branch  = $options['branch'] ?? null;
$timeout = (int)($options['timeout'] ?? 30);

$startTime = microtime(true);
$containerName = 'dind-test-' . $name;
// Several engine guards (Dind::emptyProjectDir, HostNodeBuild::isSafeProjectDir)
// deliberately refuse to touch anything outside /home/<user>/project, so a
// tester rooted in /tmp can never reach the host-compile path at all.
$useRealHome = isset($options['real-home']);
// NOT sys_get_temp_dir(): /tmp is a RAM-backed tmpfs on many hosts (it is in
// this repo's own dev boxes), and a DinD container keeps its whole inner Docker
// storage — every seeded image and build layer — under the account dir. Five
// concurrent test accounts ate 29G of RAM here and killed a run with
// "no space left on device" while the disk was 75% free. Keep it on disk.
$diskBase = (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/panelalpha-dind-test';
if (!is_dir($diskBase)) {
    @mkdir($diskBase, 0755, true);
}
$testBaseDir = $useRealHome
    ? '/home'
    : $diskBase . '/' . $name;
// The account's own directory — the only thing this script may ever delete.
// With --real-home the base dir is /home itself, so nothing below may be
// derived from $testBaseDir when removing files.
$accountDir = "{$testBaseDir}/{$containerName}";
// Host-side build cache. Production keeps it outside the account on purpose
// (/var/cache/panelalpha/projects is not a core-container volume); keep that
// property here rather than nesting it inside the account home.
$hostCacheDir = $diskBase . '/cache-' . $name;
$engineRoot   = realpath(__DIR__ . '/../..');

if ($useRealHome && !is_dir($accountDir)) {
    fwrite(STDERR, "\033[0;31m✗ --real-home needs {$accountDir} to exist and be yours. Run:\033[0m\n");
    fwrite(STDERR, "    sudo mkdir -p {$accountDir} && sudo chown \$(id -u):\$(id -g) {$accountDir}\n");
    exit(1);
}
if ($useRealHome && !is_writable($accountDir)) {
    fwrite(STDERR, "\033[0;31m✗ {$accountDir} exists but is not writable by you.\033[0m\n");
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function deriveName(string $source): string
{
    if (is_dir($source) || is_file($source)) return basename(rtrim($source, '/'));
    $parts = explode('/', rtrim($source, '/'));
    return preg_replace('/\.git$/', '', end($parts)) ?: 'app';
}

function step(string $m): void { fwrite(STDERR, "\n\033[1;36m▶ {$m}\033[0m\n"); }
function ok(string $m): void   { fwrite(STDERR, "  \033[0;32m✓ {$m}\033[0m\n"); }
function info(string $m): void { fwrite(STDERR, "  \033[0;37m{$m}\033[0m\n"); }
function err(string $m): void  { fwrite(STDERR, "  \033[0;31m✗ {$m}\033[0m\n"); }

/**
 * Per-layer timings out of the BuildKit progress stream.
 *
 * BuildKit prints `#14 [7/9] RUN npm ci ...` for a step's identity and
 * `#14 DONE 12.3s` / `#14 CACHED` for its outcome, on separate lines and not
 * necessarily adjacent. Correlating them by step number is what turns the
 * single opaque "start" phase into "npm ci 12.3s, composer install 41.0s,
 * npm run build 30.1s" — the level the deploy pipeline needs in order to know
 * which stage to optimise.
 *
 * @return list<array{step: string, desc: string, secs: float, cached: bool}>
 */
function parseBuildSteps(string $log): array
{
    $desc = [];
    $secs = [];
    $cached = [];
    foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
        $line = rtrim($line);
        if (preg_match('/^#(\d+)\s+\[([^\]]*)\]\s+(.+)$/', $line, $m) === 1) {
            // Skip BuildKit's own bookkeeping stages.
            if (str_starts_with($m[2], 'internal')) {
                continue;
            }
            $desc[$m[1]] = trim($m[3]);
        } elseif (preg_match('/^#(\d+)\s+DONE\s+([\d.]+)s/', $line, $m) === 1) {
            // A step can report DONE more than once; keep the largest.
            $secs[$m[1]] = max($secs[$m[1]] ?? 0.0, (float) $m[2]);
        } elseif (preg_match('/^#(\d+)\s+CACHED/', $line, $m) === 1) {
            $cached[$m[1]] = true;
        }
    }

    $steps = [];
    foreach ($desc as $n => $text) {
        $steps[] = [
            'step' => '#' . $n,
            'desc' => $text,
            'secs' => $secs[$n] ?? 0.0,
            'cached' => isset($cached[$n]),
        ];
    }
    usort($steps, static fn (array $a, array $b): int => $b['secs'] <=> $a['secs']);

    return $steps;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ── TestSystem: subclass that strips sudo/chown and redirects file I/O ────

use App\System as RealSystem;
use App\System\Filesystem as RealFilesystem;
use App\Models\User as RealUserModel;
use App\Models\Domain as RealDomain;
use Symfony\Component\Process\Process;

class TestFilesystem extends RealFilesystem
{
    /**
     * Force world-writable modes so the container user (UID 1001) can read
     * generated scripts written by the host user (UID 1000).
     */
    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
    {
        parent::filePutContents($path, $contents, null, '777');
    }

    public function copyFile(string $source, string $target, ?string $chown = null, ?string $chmod = null): void
    {
        parent::copyFile($source, $target, null, '777');
    }
}

class TestSystem extends RealSystem
{
    public string $testBaseDir;
    public string $hostCacheDir;
    public string $engineRoot;

    public function __construct(string $testBaseDir, string $engineRoot, string $hostCacheDir = '')
    {
        $this->testBaseDir = rtrim($testBaseDir, '/');
        $this->engineRoot = rtrim($engineRoot, '/');
        $this->hostCacheDir = $hostCacheDir !== ''
            ? rtrim($hostCacheDir, '/')
            : $this->testBaseDir . '/hostcache';
    }

    public function homesDirPath(): string
    {
        // Use the test dir as the homes root on the host side.
        // The compose volume maps this to /home/{username}/ inside the container.
        return $this->testBaseDir;
    }

    public function engineDirPath(): string
    {
        return $this->engineRoot;
    }

    public function projectHomeDirPath(string $username): string
    {
        return "{$this->testBaseDir}/{$username}";
    }

    public function projectDirPath(string $username): string
    {
        // DinD account layout: home and project tree share the same host dir.
        return "{$this->testBaseDir}/{$username}";
    }

    public function filesystem(): RealFilesystem
    {
        return new TestFilesystem($this);
    }

    // Process execution — strip sudo, neuter chown, redirect host-cache paths
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        return parent::exec($this->rewrite($cmd), $env, $timeout);
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return parent::runProcess($this->rewrite($cmd), $env, $timeout);
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?\App\Lib\Deploy\DeployLog\StepWatchdog $watchdog = null
    ): Process {
        return parent::runProcessWithCallbacks($this->rewrite($cmd), $env, $timeout, $onStart, $onOutput, $watchdog);
    }

    public function execOnHost(string|array $cmd, array $env = []): string
    {
        $cmd = $this->rewrite($cmd);
        $process = is_array($cmd) ? new Process($cmd) : Process::fromShellCommandLine($cmd);
        $process->run(null, $env);
        if (!$process->isSuccessful()) {
            throw new \Exception($process->getErrorOutput() ?: $process->getOutput());
        }
        return $process->getOutput();
    }

    public function runProcessOnHost(string|array $cmd, array $env = []): Process
    {
        return $this->runProcess($this->rewrite($cmd), $env);
    }

    /**
     * Redirect the host build caches out of /var/cache/panelalpha/projects.
     *
     * Production creates and owns that path as root in the host namespace
     * (see HostBuilder::prepareCacheArgv). The tester runs as an
     * unprivileged user, so both the mkdir and the `-v <src>:/var/cache/pa-js`
     * bind sources are pointed at the per-run test dir instead. The
     * container-side path (/var/cache/pa-js) is deliberately left alone.
     */
    private function redirectHostCache(string $arg): string
    {
        return str_replace(
            \App\Lib\Deploy\ProjectCache::ROOT,
            $this->hostCacheDir,
            $arg
        );
    }

    /**
     * Remove a leading `nsenter --target 1 --all` (with its flags) from an argv
     * array. Returns the argv unchanged when the prefix is absent.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private static function stripNsenter(array $cmd): array
    {
        if (($cmd[0] ?? null) !== 'nsenter') {
            return $cmd;
        }
        $i = 1;
        $count = count($cmd);
        while ($i < $count) {
            $arg = $cmd[$i];
            if ($arg === '--target' || $arg === '-t') {
                $i += 2;
                continue;
            }
            if ($arg === '--all' || $arg === '-a' || str_starts_with($arg, '-')) {
                $i++;
                continue;
            }
            break;
        }

        return array_values(array_slice($cmd, $i));
    }

    /**
     * Rewrite a command for standalone execution:
     * 1. Strip 'sudo' (we run as current user)
     * 2. Redirect 'chown' to 'docker exec {container} chown' so it runs
     *    inside the DinD container where the user exists
     */
    private function rewrite(string|array $cmd): string|array
    {
        if (!is_array($cmd)) {
            // String form: strip ALL 'sudo ' occurrences
            $cmd = preg_replace('/\bsudo\s+/', '', $cmd);
            // Same host-namespace hop removal as the array form below.
            $cmd = preg_replace('/^\s*nsenter\s+--target\s+1\s+--all\s+/', '', $cmd);
            $cmd = $this->redirectHostCache($cmd);
            // Neutralize chown — we're not root on the host
            if (preg_match('/^\s*chown\b/', $cmd)) {
                return 'true';
            }
            // mkdir -p: add -m 777 so the container user can write to it
            $cmd = preg_replace('/\bmkdir\s+-p\b(?!\s+-m\b)/', 'mkdir -p -m 777', $cmd);
            return $cmd;
        }
        // Array form: strip leading 'sudo'
        if (count($cmd) > 0 && $cmd[0] === 'sudo') {
            $cmd = array_slice($cmd, 1);
        }
        // Drop the host-namespace hop. In production the engine runs inside the
        // privileged `core` container, so reaching the Docker host means
        // `nsenter --target 1 --all`. The tester already runs natively on the
        // host, where that hop is both redundant and impossible (reading
        // /proc/1/ns/* needs root). Commands like HostNodeBuild::prepareCache-
        // Command() bake the prefix into their argv and are dispatched through
        // plain exec(), so execOnHost()'s override never sees them.
        $cmd = self::stripNsenter($cmd);
        $cmd = array_map(fn (string $a): string => $this->redirectHostCache($a), $cmd);
        // ['sh', '-c', '<script>'] carries a whole shell script as one argv
        // entry, and the engine's host-side helpers (PhpBaseImage build,
        // the engine's save|load script) put their own 'sudo' inside it.
        // Stripping only the leading sudo above leaves those embedded ones to
        // fail on a non-root host, which silently disabled the prebuilt
        // panelalpha/php base image and made every PHP deploy recompile its
        // extensions — a tester artifact that made builds look ~50s slower
        // than production. Rewrite the script body too.
        if (count($cmd) >= 3 && in_array($cmd[0], ['sh', 'bash'], true)) {
            $scriptIndex = array_search('-c', $cmd, true);
            if ($scriptIndex !== false && isset($cmd[$scriptIndex + 1])) {
                $cmd[$scriptIndex + 1] = preg_replace('/\bsudo\s+/', '', $cmd[$scriptIndex + 1]);
                $cmd[$scriptIndex + 1] = preg_replace(
                    '/\bnsenter\s+--target\s+1\s+--all\s+/',
                    '',
                    $cmd[$scriptIndex + 1]
                );
                // Embedded `chown -R uid:gid <path>` (HostNodeBuild's cache
                // prep) cannot work off-root. Production needs it so the build
                // container's --user can write the cache; widening the mode
                // reaches the same end without privileges. The trailing
                // `chmod 750` would undo that, so it is widened too.
                $cmd[$scriptIndex + 1] = preg_replace(
                    '/\bchown\s+-R\s+\S+\s+/',
                    'chmod -R 777 ',
                    $cmd[$scriptIndex + 1]
                );
                $cmd[$scriptIndex + 1] = preg_replace(
                    '/\bchmod\s+750\b/',
                    'chmod 777',
                    $cmd[$scriptIndex + 1]
                );
                $cmd[$scriptIndex + 1] = preg_replace(
                    '/\bmkdir\s+-p\b(?!\s+-m\b)/',
                    'mkdir -p -m 777',
                    $cmd[$scriptIndex + 1]
                );
            }
        }
        // chown: skip — not running as root
        if (count($cmd) > 0 && $cmd[0] === 'chown') {
            return ['true'];
        }
        // mkdir: add -m 777 so the container user can write to it
        if (count($cmd) > 0 && $cmd[0] === 'mkdir' && in_array('-p', $cmd)) {
            if (!in_array('-m', $cmd)) {
                $cmd = ['mkdir', '-p', '-m', '777', ...array_slice($cmd, 2)];
            }
        }
        return $cmd;
    }
}

// User model that doesn't touch the database — save() is a no-op, and
// getMainDomain() returns an in-memory Domain so no DB query is needed.
class InMemoryUser extends RealUserModel
{
    public function save(array $options = []): bool { return true; }

    /**
     * In production the account user owns ~/project, and HostCompile runs the
     * build container as that uid:gid so it can write dist/ and node_modules
     * back. Here the files belong to whoever ran this script, and the harness
     * neutralises chown, so the build must run as that same user — otherwise it
     * falls back to 33:33 and cannot even open package.json.
     */
    public function getUid(): ?int { return posix_getuid(); }

    public function getGid(): ?int { return posix_getgid(); }

    /**
     * Eloquent derives a relation's foreign key from the model's class name,
     * so subclassing the real User as `InMemoryUser` silently renamed
     * `user_id` to `in_memory_user_id`. Every query through a relation then
     * failed with
     *
     *     SQLSTATE[42S22]: Unknown column 'mysql_databases.in_memory_user_id'
     *
     * which is what stopped this harness deploying any application whose
     * manifest declares `database: mysql` -- ten of the fourteen shipped PHP
     * recipes, chamilo and magento and matomo among them. Name the key after
     * the model the schema was actually built for.
     */
    public function getForeignKey(): string { return 'user_id'; }

    public function getMainDomain(): ?RealDomain
    {
        $domain = new RealDomain();
        $domain->domain = 'test.local';
        $domain->type = 'main';
        $domain->details = ['ssl_disabled' => true];
        return $domain;
    }

    public function getDomains(): array
    {
        return [$this->getMainDomain()];
    }
}

// ── Main flow ─────────────────────────────────────────────────────────────

use App\System\Project\Dind;

step("DinD Deploy Tester — {$name}");
info("Source: {$source}");
info("Container: {$containerName}");
info("Test dir: {$testBaseDir}");

// --reuse-container redeploys into the account container that is already
// running. This is the ONLY way to measure production's warm path: the
// account's own BuildKit cache lives inside that container, so recreating it
// (the default) throws the cache away and every "warm" run is really cold.
// Must be decided BEFORE the stop below, or there is nothing left to reuse.
$reuseContainer = isset($options['reuse-container'])
    && trim((string) shell_exec("docker ps -q --filter name=^/{$containerName}$ 2>/dev/null")) !== '';
if (isset($options['reuse-container']) && !$reuseContainer) {
    info('--reuse-container: no running container, falling back to a cold run');
}

// Clean up previous run
if (!$reuseContainer) {
    shell_exec("docker stop {$containerName} 2>/dev/null");
    // Remove THIS account's container by name. Never `docker container prune`:
    // that is unscoped and deletes every stopped container on the host, which
    // on a box with real hosting accounts means every user container that
    // happened to be stopped at the time.
    shell_exec("docker rm -f " . escapeshellarg($containerName) . " 2>/dev/null");
} else {
    info('Reusing running container — inner Docker cache preserved');
}
// Only ever empty the account dir and the build cache — never $testBaseDir,
// which under --real-home is /home itself.

// --keep-cache leaves the host build cache (node_modules / npm / pnpm / yarn /
// bun) in place so a second run measures a warm build instead of a cold one.
// Reusing the container implies keeping everything it holds.
$cleanTargets = isset($options['keep-cache']) ? [$accountDir] : [$accountDir, $hostCacheDir];
if ($reuseContainer) {
    // Wiping the account dir would delete the inner Docker storage — the cache
    // we are here to measure. Only ~/project is replaced, by the clone step.
    // The host build cache is a different thing and still follows --keep-cache:
    // it is keyed on the account, so deploying a *different* app into a reused
    // account would otherwise overlay the previous app's node_modules onto this
    // one. npm dies on that with "Cannot read properties of null (edgesOut)".
    $cleanTargets = isset($options['keep-cache']) ? [] : [$hostCacheDir];
}
foreach ($cleanTargets as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    // The inner Docker daemon creates dirs owned by root — clean via docker
    shell_exec("docker run --rm -v " . escapeshellarg($dir) . ":/data alpine sh -c 'rm -rf /data/* /data/.[!.]*' 2>/dev/null");
}
if (!$useRealHome && is_dir($testBaseDir)) {
    rrmdir($testBaseDir);
}

// Create real engine objects with TestSystem
$system = new TestSystem($testBaseDir, $engineRoot, $hostCacheDir);
$userModel = new InMemoryUser();
// A row this account's own records point at. Nothing reads it back, but
// AppDatabase writes `mysql_databases.user_id` and a null there is rejected.
$userModel->id = 1;
$userModel->username = $containerName;
$userModel->details = [
    'template' => 'dind',
    'git_repo' => (preg_match('#^https?://|^git@|^ssh://#', $source) || is_dir($source . '/.git')) ? $source : null,
    'git_branch' => $branch,
    'UID' => 1001,
    'GID' => 1001,
    'cpu_limit' => null,
    'memory_limit' => null,
];
$project = $system->project($userModel);
$runtime = $project->runtime();
if (!$runtime instanceof Dind) {
    err("Expected DinD runtime, got " . $runtime::class);
    exit(1);
}
$dind = $runtime;

// ── Step 1: Create DinD container ──────────────────────────────────────────

step($reuseContainer ? "1. Reusing DinD container (warm)" : "1. Creating DinD container");
startPhase('create');
if (!$reuseContainer) {
    $project->createFromTemplate();
}

// The compose template mounts /home/{user}/:/home/{user}/. Since our test dir
// is under /tmp/, we mount the test dir at BOTH the test dir path (so host and
// container share the same paths for file I/O) AND /home/{user}/ (so the
// entrypoint script's /home/$(hostname)/ paths work).
// Under --real-home the host path already *is* /home/{user}/, so the template's
// own mount is correct and rewriting it would only duplicate the bind.
$composePath = $dind->composeFilePath();
if (!$useRealHome && !$reuseContainer) {
    $composeContent = file_get_contents($composePath);
    $composeContent = str_replace(
        "/home/{$containerName}/:/home/{$containerName}/",
        "{$testBaseDir}/{$containerName}/:{$testBaseDir}/{$containerName}/\n      - {$testBaseDir}/{$containerName}/:/home/{$containerName}/",
        $composeContent
    );
    file_put_contents($composePath, $composeContent);
}

// Make the home dir writable by the container user (UID 1001).
// The host user (UID 1000) created the files via createFromTemplate(),
// but the container user needs write access for git config, .env, etc.
$hostHomeDir = "{$testBaseDir}/{$containerName}";
if (!$reuseContainer) {
    shell_exec("chmod -R 777 {$hostHomeDir} 2>/dev/null");
    $project->up();
}
$phaseTimes['create'] = endPhase('create');
ok(($reuseContainer ? "DinD container reused" : "DinD container started")
    . " ({$containerName}) — {$phaseTimes['create']}");

// The account user is created by entrypoint-init.d/useradd.sh at container
// start, and every later command runs through `su <user>` (Shell.php). If the
// bind mount attached to a replaced account directory — which happens when a
// previous container is torn down and the dir recreated underneath it — that
// init dir arrives empty, no user is created, and the run dies much later with
// a baffling "su: user ... does not exist". Fail here instead, with the cause.
$userReady = false;
for ($i = 0; $i < 30; $i++) {
    $probe = shell_exec("docker exec {$containerName} id {$containerName} 2>&1");
    if ($probe !== null && str_contains($probe, 'uid=')) {
        $userReady = true;
        break;
    }
    sleep(1);
}
if (!$userReady) {
    $mounted = trim((string) shell_exec("docker exec {$containerName} ls /entrypoint-init.d/ 2>&1"));
    err("Account user '{$containerName}' was never created inside the container.");
    err('/entrypoint-init.d/ in container: ' . ($mounted === '' ? '(empty)' : str_replace("\n", ' ', $mounted)));
    err('Usually a stale bind mount: remove the container, confirm it is gone,');
    err('then re-run so the mount attaches to the current account directory.');
    exit(1);
}

// Wait for inner Docker daemon. Genuinely needed in production too — an
// account's compose/deploy commands cannot run until its nested dockerd
// answers — but production doesn't poll it in a 1s-granularity shell loop;
// this is the test tool's own (coarse) way of finding out.
step("1a. Waiting for inner Docker daemon");
startPhase('daemon-wait');
info("Waiting for inner Docker daemon...");
$ready = false;
for ($i = 0; $i < 90; $i++) {
    $check = shell_exec("docker exec {$containerName} docker info 2>/dev/null");
    // "Server:" appears even on failure — look for "Containers:" which only
    // appears when the daemon is actually connected.
    if (str_contains($check ?? '', 'Containers:')) { $ready = true; break; }
    sleep(1);
    if ($i % 10 == 0 && $i > 0) info("  ...still waiting ({$i}s)");
}
if (!$ready) { err("Inner Docker daemon did not start"); exit(1); }
$phaseTimes['daemon-wait'] = endPhase('daemon-wait');
ok("Inner Docker daemon ready — {$phaseTimes['daemon-wait']}");

// Extra fixed settling margin beyond the readiness check itself — test-only,
// exists purely to make this poll more reliable on a loaded host, not because
// anything downstream needs it. Broken into its own phase so it isn't
// mistaken for genuine daemon-startup cost.
startPhase('daemon-settle');
sleep(2);
$phaseTimes['daemon-settle'] = endPhase('daemon-settle');

// Pre-seed the configured image cache list, mirroring production boot-time
// seeding but done SYNCHRONOUSLY here so the test phases are accurate to
// measure — production's InnerDocker::seedBaseImagesInBackground() is
// fire-and-forget and does NOT block a deploy. This phase's time is real
// work, but it is not part of what a real deploy's caller waits on; see the
// "production critical path" line in the summary.
use App\Lib\Deploy\CacheManager\ImageCatalog;
step("1b. GLOBAL SEED — base images into the account's Docker");
info('One-time per account, shared by every later deploy. Production runs this');
info('in the background (InnerDocker::seedBaseImagesInBackground()), so no');
info('deploy ever waits on it — it is NOT part of any deploy\'s critical path.');
startPhase('seed');
$images = ImageCatalog::all();
if ($reuseContainer) {
    // The images are already inside the container we kept.
    $phaseTimes['seed'] = endPhase('seed');
    ok("Already seeded — reusing the global seed from this account");
} else {
    // Same call production makes, through the same port — the tester must not
    // keep its own copy of how an image reaches an account.
    $imageStore = $dind->engine()->images();
    $seedAccount = $dind->engineAccount();
    foreach ($images as $image) {
        $system->exec($imageStore->importFromHostCommand($seedAccount, $image));
    }
    $phaseTimes['seed'] = endPhase('seed');
    ok("Global seed: " . count($images) . " images — {$phaseTimes['seed']}");
}

// --seed-only prepares the account and stops: the global seed is measured once
// and every app afterwards runs with --reuse-container against it. That mirrors
// production, where an account is seeded at creation and deploys come later.
if (isset($options['seed-only'])) {
    $elapsed = round(microtime(true) - $startTime, 1);
    step("Global seed complete");
    fwrite(STDERR, "  Container:   {$containerName}\n");
    fwrite(STDERR, "  Images:      " . count($images) . "\n");
    fwrite(STDERR, "  Global seed: {$phaseTimes['seed']}\n");
    fwrite(STDERR, "  Setup total: {$elapsed}s (create {$phaseTimes['create']}, "
        . "daemon-wait {$phaseTimes['daemon-wait']}, seed {$phaseTimes['seed']})\n");
    fwrite(STDERR, "  Deploy apps into it with: --reuse-container\n\n");
    exit(0);
}

// Ensure project dir is writable by the container user (UID 1001)
$hostProjectDir = "{$testBaseDir}/{$containerName}/project";
if (!is_dir($hostProjectDir)) @mkdir($hostProjectDir, 0777, true);
shell_exec("chmod 777 {$hostProjectDir} 2>/dev/null");

// ── Step 2: Clone / import application ─────────────────────────────────────

step("2. Cloning application");
startPhase('clone');
$hostProjectDir = "{$testBaseDir}/{$containerName}/project";
if ($userModel->hasGitProject()) {
    try {
        $project->cloneUserApp();
    } catch (\Throwable $e) {
        // cloneUserApp() also runs prepareUserAppFromSources(), which may fail
        // because git-cloned files are owned by the container UID (1001) while
        // the host user (1000) needs to write generated files. Fix perms inside
        // the container (as root) and retry only the prepare step.
        if (str_contains($e->getMessage(), 'Brak dostępu') || str_contains($e->getMessage(), 'Permission denied')) {
            info("Retrying prepare step after permission fix...");
            shell_exec("docker exec {$containerName} chmod -R 777 /home/{$containerName}/project 2>/dev/null");
            $project->prepareUserAppFromSources();
        } else {
            throw $e;
        }
    }
    shell_exec("chmod -R 777 {$hostProjectDir} 2>/dev/null");
    $phaseTimes['clone'] = endPhase('clone');
    ok("Repository cloned and deployed — {$phaseTimes['clone']}");
} else {
    // Local directory — copy to the host test dir (visible inside the container
    // via the bind mount at the same path).
    // Empty ~/project first. `cp -a src/. dest/` merges, so without this a
    // reused container keeps the previous app's files (its package.json,
    // lockfile and Dockerfile) and the next detection sees a chimera. The
    // inner daemon may own some of these, hence the root container.
    if (is_dir($hostProjectDir)) {
        shell_exec(
            'docker run --rm -v ' . escapeshellarg($hostProjectDir) . ':/data alpine '
            . "sh -c 'rm -rf /data/* /data/.[!.]*' 2>/dev/null"
        );
    }
    @mkdir($hostProjectDir, 0755, true);
    $src = rtrim($source, '/') . '/.';
    shell_exec("cp -a " . escapeshellarg($src) . " " . escapeshellarg($hostProjectDir . '/'));
    ok("Local directory copied to {$hostProjectDir}");
    $project->prepareUserAppFromSources();
    $phaseTimes['clone'] = endPhase('clone');
    ok("Project detected and deploy files generated — {$phaseTimes['clone']}");
}

// Test-only workaround: Symfony demo and similar apps reject the engine's
// default APP_ENV=production (their Kernel restricts allowed envs).
// Use prod instead, which is compatible with --no-dev composer installs.
$composePath = $dind->userAppComposeFilePath();
if (is_file($composePath) && is_file($hostProjectDir . '/symfony.lock')) {
    $compose = @file_get_contents($composePath) ?: '';
    $compose = preg_replace('/^      APP_ENV: production$/m', '      APP_ENV: prod', $compose);
    file_put_contents($composePath, $compose);
}

// ── Step 3: Start the application ──────────────────────────────────────────

step("3. Starting application");
startPhase('start');
$result = $project->startUserApp();
fwrite(STDERR, $result['stderr'] . $result['stdout']);
if ($result['exit_code'] !== 0) {
    err("Application failed to start");
    exit(2);
}
$phaseTimes['start'] = endPhase('start');
ok("Application started — {$phaseTimes['start']}");

$buildSteps = parseBuildSteps($result['stdout'] . $result['stderr']);

// ── Step 4: Report status ──────────────────────────────────────────────────

// Test-only pause before checking status — arbitrary, not something a real
// deploy waits on (its caller gets control back the moment `compose up`
// returns, at the end of the 'start' phase above).
step("4a. Settling before status check (test-only)");
startPhase('settle');
sleep(min($timeout, 5));
$phaseTimes['settle'] = endPhase('settle');
ok("Settled — {$phaseTimes['settle']}");

step("4b. Status");
$containers = $dind->getContainers();
$running = 0;
foreach ($containers as $c) {
    $state = $c['State'] ?? 'unknown';
    $name = $c['Name'] ?? $c['Service'] ?? 'unknown';
    $ports = $c['Ports'] ?? '';
    $icon = $state === 'running' ? '✓' : '✗';
    fwrite(STDERR, "  {$icon} {$name} — {$state} {$ports}\n");
    if ($state === 'running') $running++;
}

// Logs
step("Recent logs (last 20 lines)");
// The container mounts the account's project dir at this fixed in-container
// path regardless of --real-home, so only the filename comes from the
// resolver — it is the one thing that varies by strategy/repo.
$runComposeFile = basename($dind->userAppComposeFileToRun());
$logs = shell_exec("docker exec {$containerName} docker compose --project-directory /home/{$containerName}/project -f /home/{$containerName}/project/{$runComposeFile} logs --tail=20 --no-color 2>&1") ?? '';
fwrite(STDERR, $logs . "\n");

// HTTP check the same way the engine does: from a container on
// pash-default-network, target the DinD container at its app port. Test-only
// verification step — a real deploy's caller does not wait for this either.
step("HTTP check");
startPhase('http-check');
$appPort = $userModel->getAppPort() ?? 8000;
$httpCode = 'unreachable';
$network = 'pash-default-network';
$checkPorts = array_values(array_unique([$appPort, 80, 8080, 3000, 5000, 8000]));
if ($running > 0) {
    for ($i = 0; $i < 15 && ($httpCode === 'unreachable' || $httpCode === '000' || $httpCode === ''); $i++) {
        foreach ($checkPorts as $port) {
            $httpCode = trim(shell_exec("docker run --network {$network} curlimages/curl sh -c 'curl -s -o /dev/null -w \"%{http_code}\" --max-time 5 http://{$containerName}:{$port}/' 2>/dev/null") ?: '000');
            if ($httpCode !== '000' && $httpCode !== '') {
                $appPort = $port;
                break;
            }
        }
        if ($httpCode === '000' || $httpCode === '') {
            sleep(2);
        }
    }
}
$phaseTimes['http-check'] = endPhase('http-check');
if (str_starts_with($httpCode, '2') || str_starts_with($httpCode, '3')) {
    ok("HTTP {$httpCode} — http://{$containerName}:{$appPort}/");
} else {
    err("HTTP {$httpCode} — http://{$containerName}:{$appPort}/");
}

// Summary
step("Summary");
$strategy = $userModel->getDeployStrategy() ?? 'unknown';
$label = $userModel->getDetails()['deploy_label'] ?? $strategy;
$elapsed = round(microtime(true) - $startTime, 1);

$phaseLabels = [
    'create'        => 'create',
    'daemon-wait'   => 'daemon-wait',
    'daemon-settle' => 'daemon-settle (test-only)',
    'seed'          => 'global-seed (one-time per account, backgrounded in prod)',
    'clone'       => 'clone/detect',
    'start'       => 'start',
    'settle'      => 'settle (test-only)',
    'http-check'  => 'http-check (test-only)',
];
$phaseOrder = array_keys($phaseLabels);
$accountedFor = 0.0;
$breakdown = [];
foreach ($phaseOrder as $key) {
    $val = $phaseTimes[$key] ?? null;
    $breakdown[] = $phaseLabels[$key] . ' ' . ($val ?? '—');
    if ($val !== null) {
        $accountedFor += (float) $val;
    }
}
$unaccounted = round($elapsed - $accountedFor, 1);

// "Production critical path" = phases a real deploy's caller actually waits on.
// Excludes seed (backgrounded via seedBaseImagesInBackground()), settle and
// http-check (verification steps this test tool adds, not part of a real deploy).
$productionPhases = ['create', 'daemon-wait', 'clone', 'start'];
$productionTotal = 0.0;
foreach ($productionPhases as $key) {
    $productionTotal += (float) ($phaseTimes[$key] ?? 0);
}
$productionTotal = round($productionTotal, 1);

$cachedSteps = count(array_filter($buildSteps, static fn (array $s): bool => $s['cached']));
$builtSecs = 0.0;
foreach ($buildSteps as $s) {
    $builtSecs += $s['secs'];
}
$builtSecs = round($builtSecs, 1);

fwrite(STDERR, "  Source:    {$source}\n");
fwrite(STDERR, "  Strategy:  {$label} ({$strategy})\n");
fwrite(STDERR, "  Railpack:  " . ($strategy === 'railpack' ? 'yes' : 'no') . "\n");
fwrite(STDERR, "  Mode:      " . ($reuseContainer ? 'warm (container reused)' : 'cold (fresh container)') . "\n");
fwrite(
    STDERR,
    "  Build:     " . count($buildSteps) . " layer(s), {$cachedSteps} cached, {$builtSecs}s spent building\n"
);
fwrite(STDERR, "  Container: {$containerName}\n");
fwrite(STDERR, "  Project:   /home/{$containerName}/project\n");
fwrite(STDERR, "  URL:       http://localhost:{$appPort}/\n");
fwrite(STDERR, "  Time:      {$elapsed}s total (" . implode(', ', $breakdown) . ", unaccounted {$unaccounted}s)\n");
fwrite(STDERR, "  Production critical path (create + daemon-wait + clone/detect + start, excludes\n");
fwrite(STDERR, "  backgrounded seeding and this script's own verification steps): ~{$productionTotal}s\n");

// Where the 'start' phase actually went. This is the level to optimise at:
// `composer install`, `npm ci` and the framework build show up by name.
if ($buildSteps !== []) {
    fwrite(STDERR, "\n  Build steps (inside the account's Docker, slowest first):\n");
    foreach ($buildSteps as $s) {
        if ($s['secs'] < 0.05 && !$s['cached']) {
            continue;
        }
        $mark = $s['cached'] ? 'CACHED' : sprintf('%6.1fs', $s['secs']);
        $desc = preg_replace('/\s+/', ' ', $s['desc']);
        if (strlen($desc) > 96) {
            $desc = substr($desc, 0, 93) . '...';
        }
        fwrite(STDERR, sprintf("    %-8s %s\n", $mark, $desc));
    }
}
fwrite(STDERR, "\n");
fwrite(STDERR, "  Inspect:   docker exec -it {$containerName} bash\n");
fwrite(STDERR, "  Logs:      docker exec {$containerName} docker compose -f /home/{$containerName}/project/{$runComposeFile} logs -f\n");
// Never interpolate $testBaseDir here — under --real-home it is /home itself,
// and this line is meant to be copy-pasteable.
fwrite(STDERR, "  Cleanup:   docker rm -f {$containerName}"
    . " && docker run --rm -v " . escapeshellarg($accountDir) . ":/data alpine sh -c 'rm -rf /data/* /data/.[!.]*'\n");

exit($running > 0 ? 0 : 3);