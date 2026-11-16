<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\ProjectCache;
use App\System\Privileged;
use App\System\Privileged\BuildContainer;

/**
 * Throwaway, hardened build containers on the host Docker daemon, working on the account's ~/project;
 * what each command is comes from {@see HostNodeBuild}. Builds argv only.
 */
final class DindHostBuilder implements HostBuilder
{
    /** Used only when no usable limit is handed in: an unreadable host, or direct construction in tests. */
    private const DEFAULT_MEMORY_LIMIT = '2g';

    /**
     * Printed by a failed host build whose memory cgroup OOM-killed a process, which otherwise leaves
     * no text when the victim is not the shell's own child.
     */
    public const OOM_REPORT = 'Out of memory: the kernel killed a process in the host build container';

    private string $memoryLimit;

    /**
     * The host's certificate authority bundle, or '' when it has none; the slim Node images ship an empty
     * `/etc/ssl/certs`, which only Node itself does not notice ({@see trustStoreMounts()}).
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
     * A build is a host resource, capped by {@see DindEngine}; the limit is passed in, not read from config,
     * because this class has no Laravel dependencies.
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
     * The limit this builder will actually use, always spelled in megabytes, since a bare `4096` is bytes to
     * `docker run --memory`. Unusable values get the default.
     */
    private static function saneMemoryLimit(?string $memoryLimit): string
    {
        $memoryLimit = trim((string) $memoryLimit);
        $configured = ServiceLimits::toMegabytes($memoryLimit);
        if ($configured === null || $configured <= 0) {
            return self::DEFAULT_MEMORY_LIMIT;
        }

        return $configured.'m';
    }

    private function memoryLimit(): string
    {
        return $this->memoryLimit;
    }

    /**
     * The whole checkout stays the mount and the subtree only the working directory, so a symlinked
     * app_root cannot point the bind mount anywhere on the host.
     *
     * @param  array<string, string|int>  $env
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
        $workdir = PhpHostBuild::workingDir($appRoot);
        $script = $this->assertBuildable($projectDir, $image, $install, $build, $isNode, $workdir);
        $cache = HostNodeBuild::cacheDirFor($account->username);
        $mounts = ['/app' => $projectDir, '/var/cache/pa-js' => $cache];
        if ($isolateNodeModules) {
            $mounts[$workdir.'/node_modules'] = $cache.'/node_modules';
        }

        $env = [...$this->trustStoreEnv(), ...$this->toolchainEnv(), ...$this->callerEnv($env)];

        return Privileged::docker()->nodeBuild(
            $this->container($account, $mounts, $workdir, $env, $image, $this->trustStoreMounts()),
            self::reportingOom($script),
        );
    }

    /**
     * The script a host Node build runs; outside ~/project, a bad image reference or nothing to run is
     * not recoverable, so it throws instead of returning a partial argv.
     */
    private function assertBuildable(
        string $projectDir,
        string $image,
        string $install,
        string $build,
        bool $isNode = true,
        string $workdir = '/app'
    ): string {
        if (! HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host Node build outside ~/project');
        }
        if (! ImageTransfer::isSafeImageRef($image)) {
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
     * Every host build's environment: per-project caches, CI so nothing prompts, no `packageManager` pinned in the
     * checkout, and the runtimes' heap share of the limit (JAVA_TOOL_OPTIONS, which MAVEN_ARGS cannot carry).
     *
     * @return array<string, string>
     */
    private function toolchainEnv(): array
    {
        return [
            'CI' => 'true',
            'HOME' => '/tmp',
            'NODE_OPTIONS' => '--max-old-space-size='.ServiceLimits::nodeHeapMbFor($this->memoryLimit()),
            'COREPACK_HOME' => '/tmp/corepack',
            'COREPACK_ENABLE_AUTO_PIN' => '0',
            'npm_config_cache' => '/var/cache/pa-js/npm',
            'npm_config_audit' => 'false',
            'npm_config_fund' => 'false',
            'npm_config_update_notifier' => 'false',
            'PNPM_STORE_DIR' => '/var/cache/pa-js/pnpm',
            'YARN_CACHE_FOLDER' => '/var/cache/pa-js/yarn',
            'BUN_INSTALL_CACHE_DIR' => '/var/cache/pa-js/bun',
            'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0',
            'PIP_CACHE_DIR' => '/var/cache/pa-js/pip',
            'GOMODCACHE' => '/var/cache/pa-js/go/mod',
            'GOCACHE' => '/var/cache/pa-js/go/build',
            'CARGO_HOME' => '/var/cache/pa-js/cargo',
            'CARGO_BUILD_JOBS' => (string) ServiceLimits::cargoJobsFor($this->memoryLimit(), self::hostCpus()),
            'MAVEN_ARGS' => '-Dmaven.repo.local=/var/cache/pa-js/m2',
            'MAVEN_CONFIG' => '/var/cache/pa-js/maven',
            'GRADLE_USER_HOME' => '/var/cache/pa-js/gradle',
            'JAVA_TOOL_OPTIONS' => '-Xmx'.ServiceLimits::javaHeapMbFor($this->memoryLimit()).'m',
            'ASTRO_TELEMETRY_DISABLED' => '1',
            'NEXT_TELEMETRY_DISABLED' => '1',
            'NUXT_TELEMETRY_DISABLED' => '1',
        ];
    }

    /**
     * The host's CA bundle mounted read-only, which works only with {@see trustStoreEnv()}.
     *
     * @return array<string, string>
     */
    private function trustStoreMounts(): array
    {
        return $this->caBundle === '' ? [] : [self::CONTAINER_CA_BUNDLE => $this->caBundle];
    }

    /**
     * A recipe's own `SSL_CERT_FILE`, merged after, wins.
     *
     * @return array<string, string>
     */
    private function trustStoreEnv(): array
    {
        return $this->caBundle === '' ? [] : ['SSL_CERT_FILE' => self::CONTAINER_CA_BUNDLE];
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
     * Shell-valid names and scalar values only, since a key with a space would inject a `docker run` flag.
     *
     * @return array<string, string>
     */
    private function callerEnv(array $env): array
    {
        $valid = [];
        foreach ($env as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $valid[$key] = (string) $value;
        }

        return $valid;
    }

    /**
     * Run a PHP project's build on the host, in the image it will be served from, so composer checks the
     * real PHP minor and extensions; `--entrypoint sh` keeps it off the image's shim.
     *
     * @param  string  $image  the shared PHP base this account was assigned
     * @param  string  $script  the build steps, already ordered
     *                          ({@see PhpHostBuild::script()})
     * @param  string  $appRoot  application subtree inside the repository, '' when
     *                           they are the same
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
        self::assertPhpBuildable($projectDir, $image, $script);

        $mounts = ['/app' => $projectDir];
        if ($withCache) {
            $mounts[PhpBaseImage::COMPOSER_CACHE_DIR] = PhpHostBuild::cacheDirFor($account->username);
        }
        $env = [
            ...PhpHostBuild::environment($withCache),
            ...($manifest !== null ? [PhpHostBuild::MANIFEST_ENV => $manifest] : []),
        ];

        return Privileged::docker()->phpBuild(
            $this->container($account, $mounts, PhpHostBuild::workingDir($appRoot), $env, $image),
            self::reportingOom($script),
        );
    }

    private static function assertPhpBuildable(string $projectDir, string $image, string $script): void
    {
        if (! HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host PHP build outside ~/project');
        }
        if (! ImageTransfer::isSafeImageRef($image)) {
            throw new \InvalidArgumentException('Unsafe PHP image ref');
        }
        if (trim($script) === '') {
            throw new \InvalidArgumentException('Host PHP build has no commands to run');
        }
    }

    /**
     * In the official composer image, pinned to the app's own PHP minor ($phpVersion), with scripts and
     * plugins disabled; advisories never refuse the build ({@see PhpHostBuild::environment()}).
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
        if (! HostNodeBuild::isSafeProjectDir($projectDir)) {
            throw new \InvalidArgumentException('Refusing host Composer install outside ~/project');
        }

        $env = [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_MAX_PARALLEL_HTTP' => '6',
            'COMPOSER_HOME' => '/tmp/composer',
            'COMPOSER_NO_BLOCKING' => '1',
            ...($manifest !== null ? [PhpHostBuild::MANIFEST_ENV => $manifest] : []),
        ];

        return Privileged::docker()->composerInstall(
            $this->container($account, ['/app' => $projectDir], PhpHostBuild::workingDir($appRoot), $env, Images::COMPOSER_IMAGE),
            self::reportingOom(self::composerCommand($phpVersion)),
        );
    }

    /** $script, saying OOM_REPORT on the way out when it failed on an OOM kill. */
    private static function reportingOom(string $script): string
    {
        return 'trap \'rc=$?; if [ "$rc" -ne 0 ] && grep -qs "^oom_kill [1-9]" /sys/fs/cgroup/memory.events; '
            .'then echo "'.self::OOM_REPORT.'" >&2; fi; exit "$rc"\' EXIT; '.$script;
    }

    /**
     * Extensions stay ignored here only: the Composer image does not carry the app's set, whereas the host
     * build runs in the shared base and checks them for real.
     */
    private static function composerCommand(?string $phpVersion): string
    {
        $install = 'composer install --no-dev --no-interaction --no-scripts --no-plugins';
        $pin = PhpHostBuild::platformPin($phpVersion);
        if ($pin === '') {
            return $install.' --ignore-platform-reqs';
        }

        return $pin.' && '.$install." --ignore-platform-req='ext-*'";
    }

    /**
     * The {@see ProjectCache} directories, made in the host namespace, as the host daemon resolves bind
     * mounts and would otherwise create them as root, which `--user` cannot write.
     *
     * @return list<string>
     */
    public function prepareCacheArgv(EngineAccount $account): array
    {
        return Privileged::host()->prepareProjectCache($account->username, $account->identity);
    }

    /**
     * A build container as the account, memory-limited, on the internet-only build network.
     *
     * @param  array<string, string>  $mounts  container path => host path
     * @param  array<string, string>  $env
     * @param  array<string, string>  $readOnlyMounts  container path => host path
     */
    private function container(
        EngineAccount $account,
        array $mounts,
        string $workdir,
        array $env,
        string $image,
        array $readOnlyMounts = []
    ): BuildContainer {
        return new BuildContainer($account->identity, $this->memoryLimit(), $this->network, $mounts, $readOnlyMounts, $workdir, $env, $image);
    }

    public function memoryLimitMb(): int
    {
        return (int) ServiceLimits::toMegabytes($this->memoryLimit);
    }

    public function memoryOrigin(): BuildMemory
    {
        return $this->memoryOrigin;
    }
}
