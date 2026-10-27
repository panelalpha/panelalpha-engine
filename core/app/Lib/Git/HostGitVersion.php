<?php

namespace App\Lib\Git;

use App\Lib\Net\EgressRefusedException;
use App\System as EngineSystem;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The git on the host, where traditional PHP hosting runs its git commands.
 *
 * Those commands are pinned to the address the egress guard checked with
 * `http.curloptResolve`, which git before 2.37 ignores without an error, so
 * a name that rebinds between the check and the fetch would get through.
 * The version is read once and cached; an upgrade is seen within the hour.
 */
final class HostGitVersion
{
    /** The first git that honours `http.curloptResolve`. */
    public const PINNING_MINIMUM = '2.37';

    private const CACHE_KEY = 'host-git-version';

    private const CACHE_SECONDS = 3600;

    /**
     * @param  \Closure(list<string>): string  $runOnHost
     */
    public function __construct(
        private readonly \Closure $runOnHost,
        private readonly Repository $cache,
    ) {}

    public static function of(EngineSystem $system): self
    {
        return new self(static fn (array $argv): string => $system->execOnHost($argv, [], 30), Cache::store());
    }

    /** `2.34.1`, or null when the host's git does not answer. */
    public function version(): ?string
    {
        try {
            return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): ?string => $this->probe());
        } catch (\Throwable) {
            return $this->probe();
        }
    }

    /**
     * Whether a command run by this git connects to the address it is pinned to.
     * A git that does not answer `--version` runs no fetch either, so it is not refused here.
     */
    public function pinsAddresses(): bool
    {
        $version = $this->version();

        return $version === null || version_compare($version, self::PINNING_MINIMUM, '>=');
    }

    /**
     * @throws EgressRefusedException when this git cannot pin a fetch from $host
     */
    public function assertPins(string $host): void
    {
        if ($this->pinsAddresses()) {
            return;
        }

        throw new EgressRefusedException(sprintf(
            'The server\'s git is %s, which cannot pin %s to the address the engine checked: git %s or newer is required. '
            .'Upgrade git on the server, or add %s to EGRESS_ALLOW_HOSTS to fetch from it unpinned.',
            $this->version(), $host, self::PINNING_MINIMUM, $host,
        ), 'git');
    }

    public static function parse(string $output): ?string
    {
        return preg_match('/^git version (\d+\.\d+(?:\.\d+)?)/m', $output, $m) === 1 ? $m[1] : null;
    }

    private function probe(): ?string
    {
        try {
            return self::parse(($this->runOnHost)(['git', '--version']));
        } catch (\Throwable) {
            return null;
        }
    }
}
