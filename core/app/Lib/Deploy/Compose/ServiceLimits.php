<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * How much of the account a service is allowed to use. A datastore keeps its
 * catalogue entry; everything else is the application, and Node production
 * images OOM under 256m, so the default is generous.
 */
final class ServiceLimits
{
    /**
     * Roles, not product names: what a service does in a stack.
     *
     * @var list<string>
     */
    private const CAPPED_APPLICATION_ROLES = [
        'worker', 'sidekiq', 'celery', 'queue', 'jobs', 'horizon', 'scheduler', 'cron',
        'app', 'web', 'api', 'backend', 'frontend', 'server', 'application', 'httpd', 'console',
    ];

    private const CAPPED_ROLE_MEMORY = '384m';

    private const DEFAULT_MEMORY = '512m';

    /** Held back from the account's budget for dockerd, sidecars and cache. */
    private const ACCOUNT_RESERVE_SHARE = 0.125;

    private const MIN_ACCOUNT_RESERVE_MB = 64;

    private const MAX_ACCOUNT_RESERVE_MB = 512;

    /** Below this an account is too small to run anything; give it the floor. */
    private const MIN_SERVICE_MEMORY_MB = 128;

    private const DEFAULT_HEAP_MB = 384;

    private const MIN_HEAP_MB = 128;

    private const HEAP_SHARE = 0.70;

    private const HEAP_HEADROOM_MB = 64;

    /** What one cargo job may take: a C++ compiler on a large unity file peaks past 1.2 GB. */
    private const CARGO_JOB_MEMORY_MB = 1536;

    private const BYTES_PER_MB = 1048576;

    /** @var array<string, float> */
    private const UNIT_TO_MB = ['k' => 1 / 1024, 'm' => 1, 'g' => 1024, 't' => 1048576];

    private const SIZE_PATTERN = '/^(\d+(?:\.\d+)?)\s*([kmgt])i?b?$/i';

    /**
     * A datastore keeps its catalogue size. Everything else answers to the
     * project's `memory_limit` when set, else the role default. The account
     * container carries the same limit and is the parent cgroup of the services,
     * so handing the figure to each of them is not additive.
     *
     * @param array<string, mixed> $service
     * @param int|null $accountMemoryMb the project's own limit, in MB
     */
    public static function memoryFor(string $name, array $service = [], ?int $accountMemoryMb = null): string
    {
        $engine = SidecarEngine::resolve($name, $service);
        $catalogued = $engine === null ? null : SidecarEngine::memoryLimitFor($engine);
        if ($catalogued !== null) {
            return $catalogued;
        }

        if ($accountMemoryMb !== null && $accountMemoryMb > 0) {
            return self::underAccountCeiling($accountMemoryMb) . 'm';
        }

        return in_array(strtolower($name), self::CAPPED_APPLICATION_ROLES, true)
            ? self::CAPPED_ROLE_MEMORY
            : self::DEFAULT_MEMORY;
    }

    /**
     * What one service may take out of the account's budget: an eighth, floored
     * at 64 MB and capped at 512. The rest is dockerd, the sidecars and page
     * cache sharing the account's cgroup, so an application holding the whole
     * budget can starve the daemon supervising it. Limits are ceilings, so
     * sidecars summing past the remainder is normal.
     */
    private static function underAccountCeiling(int $accountMemoryMb): int
    {
        $reserve = (int) round($accountMemoryMb * self::ACCOUNT_RESERVE_SHARE);
        $reserve = max(self::MIN_ACCOUNT_RESERVE_MB, min(self::MAX_ACCOUNT_RESERVE_MB, $reserve));

        // A very small account still gets the floor.
        return max(self::MIN_SERVICE_MEMORY_MB, $accountMemoryMb - $reserve);
    }

    /**
     * The V8 heap for a container of this size. Without a cap Node sizes its
     * heap from the host and is OOM-killed before a collection happens.
     *
     * @param mixed $memoryLimit
     */
    public static function nodeHeapMbFor($memoryLimit): int
    {
        $limitMb = self::toMegabytes($memoryLimit) ?? self::DEFAULT_HEAP_MB;

        return max(
            self::MIN_HEAP_MB,
            min($limitMb - self::HEAP_HEADROOM_MB, (int) floor($limitMb * self::HEAP_SHARE))
        );
    }

    /**
     * The JVM heap for a container of this size, in MB. HotSpot's
     * `MaxRAMPercentage` defaults to 25, so a JVM sizing itself inside an N-MB
     * cgroup gets N/4: the 2g build container gave Maven 512 MB and a
     * multi-module reactor died with `[ERROR] Java heap space` while three
     * quarters of the cgroup sat unused.
     *
     * @param mixed $memoryLimit
     */
    public static function javaHeapMbFor($memoryLimit): int
    {
        return self::nodeHeapMbFor($memoryLimit);
    }

    /**
     * Parallel cargo jobs for a build container of this size. Cargo runs one
     * job per CPU and a `-sys` crate's C++ gets one compiler per job: liwan's
     * libduckdb-sys was OOM-killed at 8 jobs in 5202 MB (cc1plus ~1.2 GB each).
     *
     * @param mixed $memoryLimit
     */
    public static function cargoJobsFor($memoryLimit, ?int $cpus = null): int
    {
        $limitMb = self::toMegabytes($memoryLimit) ?? self::CARGO_JOB_MEMORY_MB;
        $jobs = max(1, intdiv($limitMb, self::CARGO_JOB_MEMORY_MB));

        return $cpus !== null && $cpus > 0 ? min($cpus, $jobs) : $jobs;
    }

    /**
     * The V8 heap for a build inside the account's own container, or null when
     * there is no ceiling to size it against: a cap of ours would be smaller
     * than the host-sized default Node already takes. A cap larger than the
     * cgroup is worse than none -- the kernel kills before any collection and
     * the only trace is a bare `Killed`.
     */
    public static function nodeHeapMbForAccount(?int $accountMemoryMb): ?int
    {
        if ($accountMemoryMb === null || $accountMemoryMb <= 0) {
            return null;
        }

        return self::nodeHeapMbFor($accountMemoryMb);
    }

    /**
     * @param mixed $limit a byte count or a Compose size string ("512m")
     */
    public static function toMegabytes($limit): ?int
    {
        if (is_int($limit) || (is_string($limit) && ctype_digit($limit))) {
            return self::bytesToMegabytes((int) $limit);
        }
        if (!is_string($limit) || preg_match(self::SIZE_PATTERN, trim($limit), $m) !== 1) {
            return null;
        }

        return max(1, (int) floor((float) $m[1] * (self::UNIT_TO_MB[strtolower($m[2])] ?? 1)));
    }

    private static function bytesToMegabytes(int $bytes): ?int
    {
        if ($bytes <= 0) {
            return null;
        }

        return $bytes >= self::BYTES_PER_MB ? max(1, (int) floor($bytes / self::BYTES_PER_MB)) : $bytes;
    }
}
