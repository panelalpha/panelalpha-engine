<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;

/**
 * Throwaway build containers on the **host** Docker daemon, working on the
 * account's ~/project.
 *
 * A nested `npm ci` in a fresh account pays for a Node image load plus an empty
 * registry cache behind NAT; the host already has the image and a per-account
 * package cache. Install and build run here, writing `dist/`, `.output/` and —
 * for a stock Node runtime — `node_modules` back into ~/project.
 *
 * A host-daemon container with the customer's repository mounted in, so the
 * hardening in every argv below is load-bearing: the account's uid:gid,
 * no-new-privileges, all capabilities dropped, memory and pid caps, a network
 * that reaches the internet and nothing of the host's ({@see BuildNetwork}),
 * and a project directory that must match `~/project` exactly.
 *
 * What each command *is* comes from {@see HostNodeBuild}, which is
 * engine-neutral; this class only puts it in a container. Builds argv only.
 */
final class DindHostBuilder implements HostBuilder
{
    /** Used only when no usable limit is handed in: an unreadable host, or direct construction in tests. */
    private const DEFAULT_MEMORY_LIMIT = '2g';

    private string $memoryLimit;

    /**
     * The host's certificate authority bundle, or '' when it has none.
     *
     * A build container gets no system trust store of its own: the slim Debian
     * Node images ship an empty `/etc/ssl/certs` — no bundle file, no hashed
     * links, no `/usr/lib/ssl/cert.pem`. Node compiles its own root list in and
     * does not notice; anything else in the build does.
     *
     * Koel's Vite+ frontend builds an HTTP client in its Rust core and aborts
     * with `No CA certificates were loaded from the system`, SIGABRT before any
     * `install` stage runs. Every Go, Rust and Python tool verifying TLS
     * through the system store is subject to the same.
     *
     * {@see systemTrustStore()} for why the mount alone is not the fix.
     */
    private string $caBundle;

    /** The network build containers run on, or null for Docker's default bridge ({@see BuildNetwork}). */
    private ?string $network;

    /** Bundle locations in the order worth trying: Debian/Ubuntu, RHEL/Fedora, SUSE, Alpine. */
    private const CA_BUNDLE_CANDIDATES = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/ca-bundle.pem',
        '/etc/ssl/cert.pem',
    ];

    /** Where it is mounted, which is the path Debian images would use. */
    private const CONTAINER_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    private BuildMemory $memoryOrigin;

    /**
     * A build is a host resource: up to 8 GB, held to half the server's RAM
     * and its RAM less DEPLOY_ENGINE_MEMORY (engine#295). Passed in, not read from config,
     * because this class has no Laravel dependencies; see {@see DindEngine}.
     */
    public function __construct(
        ?string $memoryLimit = null,
        ?string $caBundle = null,
        ?string $network = null,
        ?BuildMemory $memoryOrigin = null
    ) {
        $this->memoryLimit = self::saneMemoryLimit($memoryLimit);
        $this->caBundle = $caBundle === null ? self::probeCaBundle() : trim($caBundle);
        $this->network = $network === null ? null : BuildNetwork::resolve($network);
        $this->memoryOrigin = $memoryOrigin ?? new BuildMemory((string) $memoryLimit, BuildMemory::SETTING);
    }

    public function network(): ?string
    {
        return $this->network;
    }

    /** The same builder on Docker's default bridge, for a host that has no build network. */
    public function withoutNetwork(): self
    {
        $clone = clone $this;
        $clone->network = null;

        return $clone;
    }

    /**
     * The limit this builder will actually use, always spelled in megabytes:
     * `DEPLOY_BUILD_MEMORY=4096` is 4096 MB to us but 4096 bytes to `docker run
     * --memory`, which then refuses every host build. Unusable values get the default.
     */
    private static function saneMemoryLimit(?string $memoryLimit): string
    {
        $memoryLimit = trim((string) $memoryLimit);
        $configured = ServiceLimits::toMegabytes($memoryLimit);
        if ($configured === null || $configured <= 0) {
            return self::DEFAULT_MEMORY_LIMIT;
        }

        return $configured . 'm';
    }

    private function memoryLimit(): string
    {
        return $this->memoryLimit;
    }

    /**
     * @param array<string, string|int> $env
     * @return list<string>
     */
    public function nodeBuildArgv(
        EngineAccount $account,
        string $image,
        string $install,
        string $build,
        array $env = [],
        bool $isolateNodeModules = true,
        bool $isNode = true,
        string $appRoot = ''
    ): array {
        $projectDir = $account->projectDir();
        // The whole checkout stays the mount and the subtree is only the
        // working directory, so a symlinked app_root cannot point the bind
        // mount anywhere on the host.
        $workdir = PhpHostBuild::workingDir($appRoot);
        $script = $this->assertBuildable($projectDir, $image, $install, $build, $isNode, $workdir);
        $cache = HostNodeBuild::cacheDirFor($account->username);

        return [
            ...$this->sandboxPrefix($account->identity),
            '-v', $projectDir . ':/app',
            '-v', $cache . ':/var/cache/pa-js',
            '-w', $workdir,
            ...$this->systemTrustStore(),
            ...$this->flatEnv($this->toolchainEnv()),
            ...($isolateNodeModules ? ['-v', $cache . '/node_modules:' . $workdir . '/node_modules'] : []),
            ...$this->callerEnv($env),
            $image,
            'sh',
            '-c',
            $script,
        ];
    }

    /**
     * The three ways a host Node build is refused, and the script it runs:
     * outside ~/project, an image reference that is not one, or nothing to run.
     * None is recoverable, so they throw instead of returning a partial argv.
     */
    private function assertBuildable(
        string $projectDir,
        string $image,
        string $install,
        string $build,
        bool $isNode = true,
        string $workdir = '/app'
    ): string {
        if (!HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host Node build outside ~/project');
        }
        if (!ImageTransfer::isSafeImageRef($image)) {
            throw new \InvalidArgumentException('Unsafe Node image ref');
        }

        $script = $isNode
            ? HostNodeBuild::innerScript($install, $build, $workdir)
            : HostNodeBuild::plainScript($install, $build);
        if ($script === '') {
            throw new \InvalidArgumentException('Host Node build has no install/build command');
        }

        return $script;
    }

    /**
     * Environment every host Node build gets: caches pointed at the host's
     * shared directory, and every package manager told it is on CI so none
     * waits on a prompt nobody will answer.
     *
     * @return array<string, string>
     */
    private function toolchainEnv(): array
    {
        return [
            'CI' => 'true',
            'HOME' => '/tmp',
            // Chamilo's Encore build hit the wall at ~1020 MB inside a 2 GB
            // container. The same share of the limit the runtime services get
            // ({@see ServiceHardener::withNodeHeapCap()}), so one rule covers
            // building and serving. A recipe's own NODE_OPTIONS still wins:
            // caller environment is appended after this one.
            'NODE_OPTIONS' => '--max-old-space-size=' . ServiceLimits::nodeHeapMbFor($this->memoryLimit()),
            'COREPACK_HOME' => '/tmp/corepack',
            'npm_config_cache' => '/var/cache/pa-js/npm',
            'npm_config_audit' => 'false',
            'npm_config_fund' => 'false',
            'npm_config_update_notifier' => 'false',
            'PNPM_STORE_DIR' => '/var/cache/pa-js/pnpm',
            'YARN_CACHE_FOLDER' => '/var/cache/pa-js/yarn',
            'BUN_INSTALL_CACHE_DIR' => '/var/cache/pa-js/bun',
            'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0',
            // The other toolchains' caches, in the same per-project directory
            // the JS ones use. Without them a host build re-downloads every
            // dependency: a Django rebuild spent 16s in `pip` resolving an
            // unchanged requirements file with no cache to resolve from.
            'PIP_CACHE_DIR' => '/var/cache/pa-js/pip',
            'GOMODCACHE' => '/var/cache/pa-js/go/mod',
            'GOCACHE' => '/var/cache/pa-js/go/build',
            'CARGO_HOME' => '/var/cache/pa-js/cargo',
            // Cargo defaults to one job per CPU whatever the cgroup allows, and
            // cc-rs and `make` inside build scripts take the same count.
            'CARGO_BUILD_JOBS' => (string) ServiceLimits::cargoJobsFor($this->memoryLimit(), self::hostCpus()),
            // Maven 3.9+ reads MAVEN_ARGS; older versions ignore it and keep
            // their default local repository, which is a slower build and not
            // a broken one.
            'MAVEN_ARGS' => '-Dmaven.repo.local=/var/cache/pa-js/m2',
            'GRADLE_USER_HOME' => '/var/cache/pa-js/gradle',
            // The JVM heap, for Maven and Gradle alike. JAVA_TOOL_OPTIONS, not
            // MAVEN_OPTS: MAVEN_ARGS takes Maven *CLI* arguments, so an `-Xmx`
            // there is rejected as `Unknown lifecycle phase "mx1433m"`, and
            // MAVEN_OPTS never reaches the forked surefire JVMs or a Gradle
            // worker. HotSpot's MaxRAMPercentage default is 25, so a JVM in a
            // 2 GB container takes 512 MB however much the rest is idle.
            //
            // Prepended to the command line, so a project's own `.mvn/jvm.config`
            // or Gradle `org.gradle.jvmargs` still wins.
            'JAVA_TOOL_OPTIONS' => '-Xmx' . ServiceLimits::javaHeapMbFor($this->memoryLimit()) . 'm',
            'ASTRO_TELEMETRY_DISABLED' => '1',
            'NEXT_TELEMETRY_DISABLED' => '1',
            'NUXT_TELEMETRY_DISABLED' => '1',
        ];
    }

    /**
     * Give the build container the host's trust store: the bundle mounted
     * read-only, and `SSL_CERT_FILE` naming it.
     *
     * **Both halves are needed, and neither works alone.** Measured against
     * `node:20-bookworm-slim` with `--use-openssl-ca`: mount only and variable
     * only both give `UNABLE_TO_GET_ISSUER_CERT_LOCALLY`, both together give
     * 200. The mount alone fails because OpenSSL's default CAfile in that image
     * is `/usr/lib/ssl/cert.pem`, which does not exist, and its CApath wants
     * `c_rehash` symlinks. `SSL_CERT_FILE` is also what openssl-probe reads,
     * which is how `rustls-native-certs` — and so Vite+ — finds its roots.
     *
     * Declared together in the argv so the file and the variable naming it can
     * never be split: a host with no bundle contributes neither. Caller
     * environment is applied after, so a recipe's own `SSL_CERT_FILE` wins.
     *
     * @return list<string>
     */
    private function systemTrustStore(): array
    {
        if ($this->caBundle === '') {
            return [];
        }

        return [
            '-v', $this->caBundle . ':' . self::CONTAINER_CA_BUNDLE . ':ro',
            '-e', 'SSL_CERT_FILE=' . self::CONTAINER_CA_BUNDLE,
        ];
    }

    /** The host's CPU count; /proc/cpuinfo is not namespaced. Null when unreadable. */
    private static function hostCpus(): ?int
    {
        $raw = is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
        $cpus = preg_match_all('/^processor\s*:/m', $raw);

        return $cpus > 0 ? $cpus : null;
    }

    /** The first candidate this host actually has, or '' if it has none. */
    private static function probeCaBundle(): string
    {
        foreach (self::CA_BUNDLE_CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * `-e` flags for the caller's variables.
     *
     * Names are checked against the shell's rule for one, and values must be
     * scalar: this argv reaches `docker run`, and a key with a space in it is a
     * flag injection, not a variable.
     *
     * @return list<string>
     */
    private function callerEnv(array $env): array
    {
        $argv = [];
        foreach ($env as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $argv[] = '-e';
            $argv[] = $key . '=' . $value;
        }

        return $argv;
    }

    /**
     * Run a PHP project's build on the host, in the image it will be served
     * from.
     *
     * This produces the `vendor/` the application actually runs on.
     * {@see composerInstallArgv()} is the narrower job — resolving vendor/ so a
     * *frontend* build can import from it — and it runs in the composer image
     * with every platform requirement ignored. Here the image is the account's
     * own runtime, so composer checks the PHP minor and the extension set for
     * real and a package needing ext-soap fails now, not at the first request.
     *
     * `--entrypoint sh` because a build step should not depend on the image's
     * shim indirection to be correct.
     *
     * @param string $image the shared PHP base this account was assigned
     * @param string $script the build steps, already ordered
     *        ({@see PhpHostBuild::script()})
     * @param string $appRoot application subtree inside the repository, '' when
     *        they are the same
     * @return list<string>
     */
    public function phpBuildArgv(
        EngineAccount $account,
        string $image,
        string $script,
        string $appRoot = '',
        bool $withCache = true,
        ?string $manifest = null
    ): array {
        $projectDir = $account->projectDir();
        if (!HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host PHP build outside ~/project');
        }
        if (!ImageTransfer::isSafeImageRef($image)) {
            throw new \InvalidArgumentException('Unsafe PHP image ref');
        }
        if (trim($script) === '') {
            throw new \InvalidArgumentException('Host PHP build has no commands to run');
        }

        // Without the mount the container writes its cache into its own
        // filesystem and throws it away on exit -- a slower resolve, nothing
        // worse. Mounting a directory the account cannot write is worse: docker
        // would create it as root and composer, running as the account, would
        // stop on a permission error it cannot explain.
        $cache = $withCache
            ? ['-v', PhpHostBuild::cacheDirFor($account->username) . ':' . PhpBaseImage::COMPOSER_CACHE_DIR]
            : [];

        return [
            ...$this->sandboxPrefix($account->identity),
            '--entrypoint',
            'sh',
            '-v',
            $projectDir . ':/app',
            ...$cache,
            '-w',
            PhpHostBuild::workingDir($appRoot),
            ...$this->flatEnv(PhpHostBuild::environment($withCache)),
            // The build step shares the mount, so it can resolve from the same
            // runtime manifest the other composer passes use. Null leaves it on
            // composer.json.
            ...($manifest !== null ? ['-e', PhpHostBuild::MANIFEST_ENV . '=' . $manifest] : []),
            $image,
            '-e',
            '-c',
            $script,
        ];
    }

    /**
     * Uses the official composer image, not php:*-cli — the CLI tags do not
     * ship Composer.
     *
     * $phpVersion is the minor the app actually runs on, and pinning it matters
     * more than it looks: Composer resolves against the Composer image's own,
     * newer PHP. With a blanket --ignore-platform-reqs it locked
     * symfony/http-foundation 8.1 (php >=8.4.1) for a Laravel on 8.3, and the
     * container died on a ParseError in vendor code while the deploy reported
     * success. Pinned, the same project resolves symfony 7.4.
     *
     * Extensions stay ignored: the Composer image lacks the app's set, which
     * the runtime image satisfies.
     *
     * Scripts *and* plugins are disabled — both execute arbitrary PHP from the
     * customer's repository, on the host daemon.
     *
     * @return list<string>
     */
    public function composerInstallArgv(
        EngineAccount $account,
        ?string $phpVersion = null,
        ?string $manifest = null,
        string $appRoot = ''
    ): array {
        $projectDir = $account->projectDir();
        if (!HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host Composer install outside ~/project');
        }

        return [
            ...$this->sandboxPrefix($account->identity),
            '--entrypoint',
            'sh',
            '-v',
            $projectDir . ':/app',
            '-w',
            PhpHostBuild::workingDir($appRoot),
            ...$this->flatEnv([
                'COMPOSER_ALLOW_SUPERUSER' => '1',
                'COMPOSER_MAX_PARALLEL_HTTP' => '6',
                'COMPOSER_HOME' => '/tmp/composer',
                // Why this is safe is {@see PhpHostBuild::runtimeManifest()}.
                // Null means the project's own composer.json.
                ...($manifest !== null ? [PhpHostBuild::MANIFEST_ENV => $manifest] : []),
            ]),
            Images::COMPOSER_IMAGE,
            '-c',
            self::composerCommand($phpVersion),
        ];
    }

    /**
 * What each command *is* comes from {@see HostNodeBuild}, which is
 * engine-neutral; this class only puts it in a container. Builds argv only.
     *
     * Extensions stay ignored *here* only: the Composer image does not carry
     * the app's set, whereas the host build runs in the shared base and can
     * check them for real.
     */
    private static function composerCommand(?string $phpVersion): string
    {
        $install = 'composer install --no-dev --no-interaction --no-scripts --no-plugins';
        $pin = PhpHostBuild::platformPin($phpVersion);
        if ($pin === '') {
            return $install . ' --ignore-platform-reqs';
        }

        return $pin . ' && ' . $install . " --ignore-platform-req='ext-*'";
    }

    /**
     * /var/cache/panelalpha is not a core-container volume (unlike /home), and
     * `docker run` bind-mounts are resolved by the host daemon. So mkdir and
     * chown must run in the host namespace — otherwise Docker creates the paths
     * as root and `--user` cannot write node_modules or the npm cache.
     *
     * @return list<string>
     */
    public function prepareCacheArgv(EngineAccount $account): array
    {
        return [
            'sudo',
            'nsenter',
            '--target',
            '1',
            '--all',
            'sh',
            '-c',
            // Which directories, and the one-time move off the old cache-first
            // layout, are {@see ProjectCache}'s to say -- the same on any
            // engine. This adds only the namespace to say them in.
            ProjectCache::prepareScript($account->username, $account->identity),
        ];
    }

    /**
     * The hardening every host build container shares. Anything changed here
     * changes the blast radius of running a customer's build on the host
     * daemon, so it lives in one place.
     *
     * @return list<string>
     */
    private function sandboxPrefix(string $identity): array
    {
        return [
            'sudo',
            'docker',
            'run',
            '--rm',
            '--user',
            $identity,
            '--security-opt',
            'no-new-privileges',
            '--cap-drop',
            'ALL',
            '--memory',
            $this->memoryLimit(),
            // Equal to --memory: no swap on top of it, so the limit is what the build can use.
            '--memory-swap',
            $this->memoryLimit(),
            // If the host runs out anyway, the kernel kills the build, not core or an app (engine#295).
            '--oom-score-adj',
            '1000',
            '--pids-limit',
            '512',
            // Internet only: not the engine API, the host, its LAN or the
            // metadata address (engine#246).
            ...($this->network !== null ? ['--network', $this->network] : []),
        ];
    }

    public function memoryLimitMb(): int
    {
        return (int) ServiceLimits::toMegabytes($this->memoryLimit);
    }

    public function memoryOrigin(): BuildMemory
    {
        return $this->memoryOrigin;
    }

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    private function flatEnv(array $env): array
    {
        $argv = [];
        foreach ($env as $key => $value) {
            $argv[] = '-e';
            $argv[] = $key . '=' . $value;
        }

        return $argv;
    }
}
