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

use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Models\Domain as RealDomain;
use App\Models\User as RealUserModel;
use App\System as RealSystem;
use App\System\Filesystem as RealFilesystem;
use App\System\Project as SystemProject;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;

$coreRoot = realpath(__DIR__ . '/../../..') . '/core';
require $coreRoot . '/vendor/autoload.php';
$app = require $coreRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/**
 * --key=value and bare --flag (false) options, and the positional arguments. Not
 * getopt(): it stops at the first non-option, so flags after the path were dropped.
 *
 * @param list<string> $argv
 * @return array{0: array<string, string|false>, 1: list<string>}
 */
function parseArguments(array $argv): array
{
    $options = [];
    $positional = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$key, $value] = explode('=', $body, 2);
            $options[$key] = $value;
        } else {
            $options[$body] = false;
        }
    }

    return [$options, $positional];
}

function printUsage(): void
{
    fwrite(STDERR, "Usage: php deploy.php <git-url> [--name=NAME] [--branch=BRANCH] [--timeout=SECONDS]\n");
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
    fwrite(STDERR, "  --timeout=N        Length of the test-only settle pause before the status\n");
    fwrite(STDERR, "                     check, capped at 5s. It does not wait longer for a\n");
    fwrite(STDERR, "                     build or for the app to answer.\n");
    fwrite(STDERR, "  --seed-only        Create the account and run the GLOBAL SEED, then stop.\n");
    fwrite(STDERR, "                     Run this once, then deploy every app with\n");
    fwrite(STDERR, "                     --reuse-container so the seed is paid for once.\n");
}

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
 * which stage to optimise. BuildKit's own `internal` steps are skipped, and a
 * step that reports DONE more than once keeps its largest time.
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
            if (str_starts_with($m[2], 'internal')) {
                continue;
            }
            $desc[$m[1]] = trim($m[3]);
        } elseif (preg_match('/^#(\d+)\s+DONE\s+([\d.]+)s/', $line, $m) === 1) {
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

/** Empties a directory from a root container: the inner Docker daemon owns some of what is in it. */
function emptyDirAsRoot(string $dir): void
{
    shell_exec("docker run --rm -v " . escapeshellarg($dir) . ":/data alpine sh -c 'rm -rf /data/* /data/.[!.]*' 2>/dev/null");
}

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

/** The real System with sudo and chown stripped from commands and /home/{user} redirected to a test directory. */
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

    /** The test dir is the homes root on the host side; the compose volume maps it to /home/{username}/ inside the container. */
    public function homesDirPath(): string
    {
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

    /** DinD account layout: home and project tree share the same host dir. */
    public function projectDirPath(string $username): string
    {
        return "{$this->testBaseDir}/{$username}";
    }

    public function filesystem(): RealFilesystem
    {
        return new TestFilesystem($this);
    }

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

    public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $cmd = $this->rewrite($cmd);
        $process = is_array($cmd) ? new Process($cmd) : Process::fromShellCommandLine($cmd);
        $process->setTimeout($timeout);
        $process->run(null, $env);
        if (!$process->isSuccessful()) {
            throw new \Exception($process->getErrorOutput() ?: $process->getOutput());
        }
        return $process->getOutput();
    }

    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return $this->runProcess($this->rewrite($cmd), $env, $timeout);
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

    /** Rewrite a command for standalone execution, as the current user on the host itself. */
    private function rewrite(string|array $cmd): string|array
    {
        return is_array($cmd) ? $this->rewriteArgv($cmd) : $this->rewriteCommandLine($cmd);
    }

    /**
     * Every sudo and the host-namespace hop go, chown becomes `true` (not root
     * here), and mkdir -p gets -m 777 so the container user can write there.
     */
    private function rewriteCommandLine(string $cmd): string
    {
        $cmd = preg_replace('/\bsudo\s+/', '', $cmd);
        $cmd = preg_replace('/^\s*nsenter\s+--target\s+1\s+--all\s+/', '', $cmd);
        $cmd = $this->redirectHostCache($cmd);
        if (preg_match('/^\s*chown\b/', $cmd)) {
            return 'true';
        }

        return preg_replace('/\bmkdir\s+-p\b(?!\s+-m\b)/', 'mkdir -p -m 777', $cmd);
    }

    /**
     * The argv form of rewriteCommandLine(). The `nsenter --target 1 --all` hop
     * reaches the host from production's core container; the tester already runs
     * there, where it is redundant and needs root. Commands that bake it into
     * their argv run through plain exec(), so execOnHost() alone never sees them.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private function rewriteArgv(array $cmd): array
    {
        if (count($cmd) > 0 && $cmd[0] === 'sudo') {
            $cmd = array_slice($cmd, 1);
        }
        $cmd = self::stripNsenter($cmd);
        $cmd = array_map(fn (string $a): string => $this->redirectHostCache($a), $cmd);
        $cmd = self::rewriteEmbeddedScript($cmd);
        if (count($cmd) > 0 && $cmd[0] === 'chown') {
            return ['true'];
        }
        if (count($cmd) > 0 && $cmd[0] === 'mkdir' && in_array('-p', $cmd) && !in_array('-m', $cmd)) {
            $cmd = ['mkdir', '-p', '-m', '777', ...array_slice($cmd, 2)];
        }

        return $cmd;
    }

    /**
     * An `sh -c` / `bash -c` script carries its own sudo and nsenter (the PHP base
     * image build, the image transfer), which would fail off-root and silently
     * skip the prebuilt base. Its `chown -R` (HostNodeBuild's cache prep) becomes
     * a world-writable chmod, which the trailing `chmod 750` must not undo.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private static function rewriteEmbeddedScript(array $cmd): array
    {
        if (count($cmd) < 3 || !in_array($cmd[0], ['sh', 'bash'], true)) {
            return $cmd;
        }
        $scriptIndex = array_search('-c', $cmd, true);
        if ($scriptIndex === false || !isset($cmd[$scriptIndex + 1])) {
            return $cmd;
        }
        $cmd[$scriptIndex + 1] = preg_replace(
            [
                '/\bsudo\s+/',
                '/\bnsenter\s+--target\s+1\s+--all\s+/',
                '/\bchown\s+-R\s+\S+\s+/',
                '/\bchmod\s+750\b/',
                '/\bmkdir\s+-p\b(?!\s+-m\b)/',
            ],
            ['', '', 'chmod -R 777 ', 'chmod 777', 'mkdir -p -m 777'],
            $cmd[$scriptIndex + 1]
        );

        return $cmd;
    }
}

/** A User that never touches the database: save() is a no-op and the main domain lives in memory. */
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
     * Eloquent derives a relation's foreign key from the model's class name, which
     * would be `in_memory_user_id` here; the schema was built for the real User.
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

/** One deploy of one source into one DinD test account, timed phase by phase. */
class DindDeployTest
{
    private const PHASE_LABELS = [
        'create'        => 'create',
        'daemon-wait'   => 'daemon-wait',
        'daemon-settle' => 'daemon-settle (test-only)',
        'seed'          => 'global-seed (one-time per account, backgrounded in prod)',
        'clone'       => 'clone/detect',
        'start'       => 'start',
        'settle'      => 'settle (test-only)',
        'http-check'  => 'http-check (test-only)',
    ];

    /** What a real deploy's caller waits on: not seeding (backgrounded) nor this script's own checks. */
    private const PRODUCTION_PHASES = ['create', 'daemon-wait', 'clone', 'start'];

    private const HTTP_NETWORK = 'pash-default-network';

    private float $startTime;
    private float $phaseStart;
    /** @var array<string, string> */
    private array $phaseTimes = [];

    private string $name;
    private mixed $branch;
    private int $timeout;
    private string $containerName;
    private bool $useRealHome;
    private string $testBaseDir;
    private string $accountDir;
    private string $hostCacheDir;
    private string $engineRoot;
    private string $projectDir;
    private bool $reuseContainer = false;

    private TestSystem $system;
    private InMemoryUser $userModel;
    private SystemProject $project;
    private Dind $dind;

    /** @param array<string, string|false> $options */
    public function __construct(private string $source, private array $options)
    {
        $this->name = (string) ($options['name'] ?? deriveName($source));
        $this->branch = $options['branch'] ?? null;
        $this->timeout = (int) ($options['timeout'] ?? 30);

        $this->startTime = microtime(true);
        $this->phaseStart = $this->startTime;
        $this->containerName = 'dind-test-' . $this->name;
        $this->useRealHome = isset($options['real-home']);
        $diskBase = self::diskBase();
        $this->testBaseDir = $this->useRealHome ? '/home' : $diskBase . '/' . $this->name;
        $this->accountDir = "{$this->testBaseDir}/{$this->containerName}";
        $this->hostCacheDir = $diskBase . '/cache-' . $this->name;
        $this->engineRoot = realpath(__DIR__ . '/../../..');
        $this->projectDir = "{$this->accountDir}/project";
    }

    public function run(): int
    {
        if (!$this->realHomeUsable()) {
            return 1;
        }
        $this->printHeader();
        $this->decideContainerReuse();
        $this->clearPreviousRun();
        if (!$this->createEngineObjects()) {
            return 1;
        }

        $this->createContainer();
        if (!$this->waitForAccountUser() || !$this->waitForInnerDocker()) {
            return 1;
        }
        $this->settleDaemon();
        $imageCount = $this->seedImages();
        if (isset($this->options['seed-only'])) {
            $this->printSeedSummary($imageCount);

            return 0;
        }

        $this->prepareProjectDir();
        $this->importApplication();
        $this->useSymfonyProdEnv();
        $result = $this->startApplication();
        if ($result === null) {
            return 2;
        }
        $buildSteps = parseBuildSteps($result['stdout'] . $result['stderr']);

        $this->settle();
        $running = $this->printContainerStatus();
        $runComposeFile = $this->printRecentLogs();
        $appPort = $this->checkHttp($running);
        $this->printSummary($buildSteps, $runComposeFile, $appPort);

        return $running > 0 ? 0 : 3;
    }

    /**
     * NOT sys_get_temp_dir(): /tmp is a RAM-backed tmpfs on many hosts, and a DinD
     * account keeps its whole inner Docker storage under its dir. Keep it on disk.
     */
    private static function diskBase(): string
    {
        $diskBase = (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/panelalpha-dind-test';
        if (!is_dir($diskBase)) {
            @mkdir($diskBase, 0755, true);
        }

        return $diskBase;
    }

    /**
     * --real-home puts the account at /home/<container>, the only place the engine's
     * guards (Dind::emptyProjectDir, HostNodeBuild::isSafeProjectDir) let it work.
     */
    private function realHomeUsable(): bool
    {
        if (!$this->useRealHome) {
            return true;
        }
        if (!is_dir($this->accountDir)) {
            fwrite(STDERR, "\033[0;31m✗ --real-home needs {$this->accountDir} to exist and be yours. Run:\033[0m\n");
            fwrite(STDERR, "    sudo mkdir -p {$this->accountDir} && sudo chown \$(id -u):\$(id -g) {$this->accountDir}\n");

            return false;
        }
        if (!is_writable($this->accountDir)) {
            fwrite(STDERR, "\033[0;31m✗ {$this->accountDir} exists but is not writable by you.\033[0m\n");

            return false;
        }

        return true;
    }

    private function printHeader(): void
    {
        step("DinD Deploy Tester — {$this->name}");
        info("Source: {$this->source}");
        info("Container: {$this->containerName}");
        info("Test dir: {$this->testBaseDir}");
    }

    /**
     * --reuse-container keeps the running account container and its BuildKit
     * cache: the only way to measure production's warm path.
     */
    private function decideContainerReuse(): void
    {
        $this->reuseContainer = isset($this->options['reuse-container'])
            && trim((string) shell_exec("docker ps -q --filter name=^/{$this->containerName}$ 2>/dev/null")) !== '';
        if (isset($this->options['reuse-container']) && !$this->reuseContainer) {
            info('--reuse-container: no running container, falling back to a cold run');
        }
    }

    /**
     * Removes THIS account's container by name, never `docker container prune`,
     * which deletes every stopped container on the host. Empties only the account
     * dir and the build cache: under --real-home $testBaseDir is /home itself.
     */
    private function clearPreviousRun(): void
    {
        if (!$this->reuseContainer) {
            shell_exec("docker stop {$this->containerName} 2>/dev/null");
            shell_exec("docker rm -f " . escapeshellarg($this->containerName) . " 2>/dev/null");
        } else {
            info('Reusing running container — inner Docker cache preserved');
        }
        foreach ($this->cleanTargets() as $dir) {
            if (is_dir($dir)) {
                emptyDirAsRoot($dir);
            }
        }
        if (!$this->useRealHome && is_dir($this->testBaseDir)) {
            rrmdir($this->testBaseDir);
        }
    }

    /**
     * A reused container keeps its account dir, which holds the inner Docker
     * storage; the host build cache goes unless --keep-cache, as it is keyed on
     * the account and a different app would otherwise inherit its node_modules.
     *
     * @return list<string>
     */
    private function cleanTargets(): array
    {
        $keepCache = isset($this->options['keep-cache']);
        if ($this->reuseContainer) {
            return $keepCache ? [] : [$this->hostCacheDir];
        }

        return $keepCache ? [$this->accountDir] : [$this->accountDir, $this->hostCacheDir];
    }

    /** The real Project and Dind runtime over TestSystem; user id 1 because AppDatabase rejects a null user_id. */
    private function createEngineObjects(): bool
    {
        $this->system = new TestSystem($this->testBaseDir, $this->engineRoot, $this->hostCacheDir);
        $this->userModel = new InMemoryUser();
        $this->userModel->id = 1;
        $this->userModel->username = $this->containerName;
        $this->userModel->details = [
            'template' => 'dind',
            'git_repo' => (preg_match('#^https?://|^git@|^ssh://#', $this->source) || is_dir($this->source . '/.git')) ? $this->source : null,
            'git_branch' => $this->branch,
            'UID' => 1001,
            'GID' => 1001,
            'cpu_limit' => null,
            'memory_limit' => null,
        ];
        $this->project = $this->system->project($this->userModel);
        $runtime = $this->project->runtime();
        if (!$runtime instanceof Dind) {
            err("Expected DinD runtime, got " . $runtime::class);

            return false;
        }
        $this->dind = $runtime;

        return true;
    }

    /** The account's home is made writable for the container user (UID 1001); the host user created it. */
    private function createContainer(): void
    {
        step($this->reuseContainer ? "1. Reusing DinD container (warm)" : "1. Creating DinD container");
        $this->startPhase();
        if (!$this->reuseContainer) {
            $this->project->createFromTemplate();
            if (!$this->useRealHome) {
                $this->mountTestDirAtBothPaths();
            }
            shell_exec("chmod -R 777 {$this->accountDir} 2>/dev/null");
            $this->project->up();
        }
        $this->endPhase('create');
        ok(($this->reuseContainer ? "DinD container reused" : "DinD container started")
            . " ({$this->containerName}) — {$this->phaseTimes['create']}");
    }

    /**
     * The template mounts /home/{user}/ at itself. Outside /home the test dir is
     * mounted at its own path (shared file I/O) and at /home/{user}/ (the entrypoint's paths).
     */
    private function mountTestDirAtBothPaths(): void
    {
        $composePath = $this->dind->composeFilePath();
        $composeContent = file_get_contents($composePath);
        $composeContent = str_replace(
            "/home/{$this->containerName}/:/home/{$this->containerName}/",
            "{$this->testBaseDir}/{$this->containerName}/:{$this->testBaseDir}/{$this->containerName}/\n      - {$this->testBaseDir}/{$this->containerName}/:/home/{$this->containerName}/",
            $composeContent
        );
        file_put_contents($composePath, $composeContent);
    }

    /**
     * The account user comes from /entrypoint-init.d/useradd.sh. A stale bind
     * mount leaves that dir empty, which would surface much later as "su: user
     * ... does not exist"; this fails here instead, with the cause.
     */
    private function waitForAccountUser(): bool
    {
        for ($i = 0; $i < 30; $i++) {
            $probe = shell_exec("docker exec {$this->containerName} id {$this->containerName} 2>&1");
            if ($probe !== null && str_contains($probe, 'uid=')) {
                return true;
            }
            sleep(1);
        }
        $mounted = trim((string) shell_exec("docker exec {$this->containerName} ls /entrypoint-init.d/ 2>&1"));
        err("Account user '{$this->containerName}' was never created inside the container.");
        err('/entrypoint-init.d/ in container: ' . ($mounted === '' ? '(empty)' : str_replace("\n", ' ', $mounted)));
        err('Usually a stale bind mount: remove the container, confirm it is gone,');
        err('then re-run so the mount attaches to the current account directory.');

        return false;
    }

    /**
     * Production also waits for the nested dockerd, only not in a 1 s poll. "Server:"
     * shows even on failure; "Containers:" only once the daemon answers.
     */
    private function waitForInnerDocker(): bool
    {
        step("1a. Waiting for inner Docker daemon");
        $this->startPhase();
        info("Waiting for inner Docker daemon...");
        $ready = false;
        for ($i = 0; $i < 90; $i++) {
            $check = shell_exec("docker exec {$this->containerName} docker info 2>/dev/null");
            if (str_contains($check ?? '', 'Containers:')) { $ready = true; break; }
            sleep(1);
            if ($i % 10 == 0 && $i > 0) info("  ...still waiting ({$i}s)");
        }
        if (!$ready) {
            err("Inner Docker daemon did not start");

            return false;
        }
        $this->endPhase('daemon-wait');
        ok("Inner Docker daemon ready — {$this->phaseTimes['daemon-wait']}");

        return true;
    }

    /** A test-only margin for a loaded host, its own phase so it is not taken for daemon startup. */
    private function settleDaemon(): void
    {
        $this->startPhase();
        sleep(2);
        $this->endPhase('daemon-settle');
    }

    /**
     * The global seed through the same port production uses, but synchronously:
     * production's InnerDocker::seedBaseImagesInBackground() never blocks a deploy.
     */
    private function seedImages(): int
    {
        step("1b. GLOBAL SEED — base images into the account's Docker");
        info('One-time per account, shared by every later deploy. Production runs this');
        info('in the background (InnerDocker::seedBaseImagesInBackground()), so no');
        info('deploy ever waits on it — it is NOT part of any deploy\'s critical path.');
        $this->startPhase();
        $images = ImageCatalog::all();
        if ($this->reuseContainer) {
            $this->endPhase('seed');
            ok("Already seeded — reusing the global seed from this account");

            return count($images);
        }
        $imageStore = $this->dind->engine()->images();
        $seedAccount = $this->dind->engineAccount();
        foreach ($images as $image) {
            $this->system->exec($imageStore->importFromHostCommand($seedAccount, $image));
        }
        $this->endPhase('seed');
        ok("Global seed: " . count($images) . " images — {$this->phaseTimes['seed']}");

        return count($images);
    }

    /** --seed-only stops here: the seed is measured once, and every app then runs with --reuse-container. */
    private function printSeedSummary(int $imageCount): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 1);
        step("Global seed complete");
        fwrite(STDERR, "  Container:   {$this->containerName}\n");
        fwrite(STDERR, "  Images:      " . $imageCount . "\n");
        fwrite(STDERR, "  Global seed: {$this->phaseTimes['seed']}\n");
        fwrite(STDERR, "  Setup total: {$elapsed}s (create {$this->phaseTimes['create']}, "
            . "daemon-wait {$this->phaseTimes['daemon-wait']}, seed {$this->phaseTimes['seed']})\n");
        fwrite(STDERR, "  Deploy apps into it with: --reuse-container\n\n");
    }

    /** Writable by the container user (UID 1001). */
    private function prepareProjectDir(): void
    {
        if (!is_dir($this->projectDir)) @mkdir($this->projectDir, 0777, true);
        shell_exec("chmod 777 {$this->projectDir} 2>/dev/null");
    }

    private function importApplication(): void
    {
        step("2. Cloning application");
        $this->startPhase();
        if ($this->userModel->hasGitProject()) {
            $this->cloneRepository();
        } else {
            $this->copyLocalDirectory();
        }
    }

    private function cloneRepository(): void
    {
        $this->cloneWithPermissionRetry();
        shell_exec("chmod -R 777 {$this->projectDir} 2>/dev/null");
        $this->endPhase('clone');
        ok("Repository cloned and deployed — {$this->phaseTimes['clone']}");
    }

    /**
     * cloneUserApp() also prepares the deploy files, which fails when the clone
     * is owned by the container's UID (1001): the files are opened up from inside
     * the container, as root, and only the prepare step runs again.
     */
    private function cloneWithPermissionRetry(): void
    {
        try {
            $this->project->cloneUserApp();
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Brak dostępu') && !str_contains($e->getMessage(), 'Permission denied')) {
                throw $e;
            }
            info("Retrying prepare step after permission fix...");
            shell_exec("docker exec {$this->containerName} chmod -R 777 /home/{$this->containerName}/project 2>/dev/null");
            $this->project->prepareUserAppFromSources();
        }
    }

    /**
     * Into ~/project, emptied first: `cp -a src/. dest/` merges, so a reused
     * container would keep the previous app's files and detection would see both.
     */
    private function copyLocalDirectory(): void
    {
        if (is_dir($this->projectDir)) {
            emptyDirAsRoot($this->projectDir);
        }
        @mkdir($this->projectDir, 0755, true);
        $src = rtrim($this->source, '/') . '/.';
        shell_exec("cp -a " . escapeshellarg($src) . " " . escapeshellarg($this->projectDir . '/'));
        ok("Local directory copied to {$this->projectDir}");
        $this->project->prepareUserAppFromSources();
        $this->endPhase('clone');
        ok("Project detected and deploy files generated — {$this->phaseTimes['clone']}");
    }

    /**
     * Test-only: Symfony apps restrict their allowed envs and reject the engine's
     * APP_ENV=production; prod works with --no-dev composer installs.
     */
    private function useSymfonyProdEnv(): void
    {
        $composePath = $this->dind->userAppComposeFilePath();
        if (is_file($composePath) && is_file($this->projectDir . '/symfony.lock')) {
            $compose = @file_get_contents($composePath) ?: '';
            $compose = preg_replace('/^      APP_ENV: production$/m', '      APP_ENV: prod', $compose);
            file_put_contents($composePath, $compose);
        }
    }

    /** @return array{stdout: string, stderr: string, exit_code: int}|null null when the app did not start */
    private function startApplication(): ?array
    {
        step("3. Starting application");
        $this->startPhase();
        $result = $this->project->startUserApp();
        fwrite(STDERR, $result['stderr'] . $result['stdout']);
        if ($result['exit_code'] !== 0) {
            err("Application failed to start");

            return null;
        }
        $this->endPhase('start');
        ok("Application started — {$this->phaseTimes['start']}");

        return $result;
    }

    /** A test-only pause: a real deploy's caller gets control back once `compose up` returns. */
    private function settle(): void
    {
        step("4a. Settling before status check (test-only)");
        $this->startPhase();
        sleep(min($this->timeout, 5));
        $this->endPhase('settle');
        ok("Settled — {$this->phaseTimes['settle']}");
    }

    /** @return int how many of the app's containers are running */
    private function printContainerStatus(): int
    {
        step("4b. Status");
        $running = 0;
        foreach ($this->dind->getContainers() as $c) {
            $state = $c['State'] ?? 'unknown';
            $name = $c['Name'] ?? $c['Service'] ?? 'unknown';
            $ports = $c['Ports'] ?? '';
            $icon = $state === 'running' ? '✓' : '✗';
            fwrite(STDERR, "  {$icon} {$name} — {$state} {$ports}\n");
            if ($state === 'running') $running++;
        }

        return $running;
    }

    /**
     * The container mounts the project at this fixed path whatever --real-home
     * says; only the compose file's name varies, by strategy and repository.
     *
     * @return string that compose file's name
     */
    private function printRecentLogs(): string
    {
        step("Recent logs (last 20 lines)");
        $runComposeFile = basename($this->dind->userAppComposeFileToRun());
        $logs = shell_exec("docker exec {$this->containerName} docker compose --project-directory /home/{$this->containerName}/project -f /home/{$this->containerName}/project/{$runComposeFile} logs --tail=20 --no-color 2>&1") ?? '';
        fwrite(STDERR, $logs . "\n");

        return $runComposeFile;
    }

    /**
     * As the engine checks it, from a container on pash-default-network; test-only,
     * a real deploy's caller does not wait for it.
     *
     * @return int the port that answered, else the app's own
     */
    private function checkHttp(int $running): int
    {
        step("HTTP check");
        $this->startPhase();
        $appPort = $this->userModel->getAppPort() ?? 8000;
        $httpCode = 'unreachable';
        if ($running > 0) {
            [$httpCode, $appPort] = $this->pollHttp($appPort);
        }
        $this->endPhase('http-check');
        if (str_starts_with($httpCode, '2') || str_starts_with($httpCode, '3')) {
            ok("HTTP {$httpCode} — http://{$this->containerName}:{$appPort}/");
        } else {
            err("HTTP {$httpCode} — http://{$this->containerName}:{$appPort}/");
        }

        return $appPort;
    }

    /**
     * Tries the app's port and the usual ones until one answers, 15 rounds at most.
     *
     * @return array{0: string, 1: int} the last HTTP code, and the port that answered or $appPort
     */
    private function pollHttp(int $appPort): array
    {
        $httpCode = 'unreachable';
        $checkPorts = array_values(array_unique([$appPort, 80, 8080, 3000, 5000, 8000]));
        for ($i = 0; $i < 15 && ($httpCode === 'unreachable' || $httpCode === '000' || $httpCode === ''); $i++) {
            foreach ($checkPorts as $port) {
                $httpCode = $this->httpCode($port);
                if ($httpCode !== '000' && $httpCode !== '') {
                    $appPort = $port;
                    break;
                }
            }
            if ($httpCode === '000' || $httpCode === '') {
                sleep(2);
            }
        }

        return [$httpCode, $appPort];
    }

    private function httpCode(int $port): string
    {
        $network = self::HTTP_NETWORK;

        return trim(shell_exec("docker run --network {$network} curlimages/curl sh -c 'curl -s -o /dev/null -w \"%{http_code}\" --max-time 5 http://{$this->containerName}:{$port}/' 2>/dev/null") ?: '000');
    }

    /** @param list<array{step: string, desc: string, secs: float, cached: bool}> $buildSteps */
    private function printSummary(array $buildSteps, string $runComposeFile, int $appPort): void
    {
        step("Summary");
        $strategy = $this->userModel->getDeployStrategy() ?? 'unknown';
        $label = $this->userModel->getDetails()['deploy_label'] ?? $strategy;
        $elapsed = round(microtime(true) - $this->startTime, 1);
        [$breakdown, $unaccounted] = $this->phaseBreakdown($elapsed);
        $productionTotal = $this->productionCriticalPath();
        $cachedSteps = count(array_filter($buildSteps, static fn (array $s): bool => $s['cached']));
        $builtSecs = round(array_sum(array_column($buildSteps, 'secs')), 1);

        fwrite(STDERR, "  Source:    {$this->source}\n");
        fwrite(STDERR, "  Strategy:  {$label} ({$strategy})\n");
        fwrite(STDERR, "  Railpack:  " . ($strategy === 'railpack' ? 'yes' : 'no') . "\n");
        fwrite(STDERR, "  Mode:      " . ($this->reuseContainer ? 'warm (container reused)' : 'cold (fresh container)') . "\n");
        fwrite(
            STDERR,
            "  Build:     " . count($buildSteps) . " layer(s), {$cachedSteps} cached, {$builtSecs}s spent building\n"
        );
        fwrite(STDERR, "  Container: {$this->containerName}\n");
        fwrite(STDERR, "  Project:   /home/{$this->containerName}/project\n");
        fwrite(STDERR, "  URL:       http://localhost:{$appPort}/\n");
        fwrite(STDERR, "  Time:      {$elapsed}s total (" . implode(', ', $breakdown) . ", unaccounted {$unaccounted}s)\n");
        fwrite(STDERR, "  Production critical path (create + daemon-wait + clone/detect + start, excludes\n");
        fwrite(STDERR, "  backgrounded seeding and this script's own verification steps): ~{$productionTotal}s\n");
        $this->printBuildSteps($buildSteps);
        fwrite(STDERR, "\n");
        $this->printFollowUpCommands($runComposeFile);
    }

    /**
     * Every phase's label and time ("—" when it did not run), and what no phase accounts for.
     *
     * @return array{0: list<string>, 1: float}
     */
    private function phaseBreakdown(float $elapsed): array
    {
        $accountedFor = 0.0;
        $breakdown = [];
        foreach (self::PHASE_LABELS as $key => $label) {
            $val = $this->phaseTimes[$key] ?? null;
            $breakdown[] = $label . ' ' . ($val ?? '—');
            if ($val !== null) {
                $accountedFor += (float) $val;
            }
        }

        return [$breakdown, round($elapsed - $accountedFor, 1)];
    }

    private function productionCriticalPath(): float
    {
        $productionTotal = 0.0;
        foreach (self::PRODUCTION_PHASES as $key) {
            $productionTotal += (float) ($this->phaseTimes[$key] ?? 0);
        }

        return round($productionTotal, 1);
    }

    /**
     * Where the 'start' phase went, slowest first: `composer install`, `npm ci`
     * and the framework build by name, the level to optimise at.
     *
     * @param list<array{step: string, desc: string, secs: float, cached: bool}> $buildSteps
     */
    private function printBuildSteps(array $buildSteps): void
    {
        if ($buildSteps === []) {
            return;
        }
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

    /** Copy-pasteable, so the cleanup names the account dir, never $testBaseDir (/home under --real-home). */
    private function printFollowUpCommands(string $runComposeFile): void
    {
        fwrite(STDERR, "  Inspect:   docker exec -it {$this->containerName} bash\n");
        fwrite(STDERR, "  Logs:      docker exec {$this->containerName} docker compose -f /home/{$this->containerName}/project/{$runComposeFile} logs -f\n");
        fwrite(STDERR, "  Cleanup:   docker rm -f {$this->containerName}"
            . " && docker run --rm -v " . escapeshellarg($this->accountDir) . ":/data alpine sh -c 'rm -rf /data/* /data/.[!.]*'\n");
    }

    private function startPhase(): void
    {
        $this->phaseStart = microtime(true);
    }

    /** Records the time since startPhase() as the phase's, e.g. "12.3s". */
    private function endPhase(string $phase): void
    {
        $this->phaseTimes[$phase] = round(microtime(true) - $this->phaseStart, 1) . 's';
    }
}

[$options, $positional] = parseArguments($argv);
if (isset($options['help']) || count($positional) === 0) {
    printUsage();
    exit(1);
}

exit((new DindDeployTest($positional[0], $options))->run());
