<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * How much of the account a service is allowed to use: a datastore keeps its catalogue entry, the rest is
 * the application, with a generous default since Node production images OOM under 256m.
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

    /** A heap the service pins with `-Xmx` gets twice its size: Elasticsearch's own rule, the rest is off-heap. */
    private const PINNED_HEAP_FACTOR = 2;

    private const PINNED_HEAP_PATTERN = '/(?:^|\s)-Xmx(\d+[kmgt]?)(?=\s|$)/i';

    private const BYTES_PER_MB = 1048576;

    /** @var array<string, float> */
    private const UNIT_TO_MB = ['k' => 1 / 1024, 'm' => 1, 'g' => 1024, 't' => 1048576];

    private const SIZE_PATTERN = '/^(\d+(?:\.\d+)?)\s*([kmgt])i?b?$/i';

    /**
     * A datastore keeps its catalogue size; the rest answer to the project's `memory_limit`, else the role
     * default. The account container is the services' parent cgroup, so the figure is not additive.
     *
     * @param  array<string, mixed>  $service
     * @param  int|null  $accountMemoryMb  the project's own limit, in MB
     */
    public static function memoryFor(string $name, array $service = [], ?int $accountMemoryMb = null): string
    {
        return self::withRoomForPinnedHeap(self::catalogueOrRoleMemory($name, $service, $accountMemoryMb), $service);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function catalogueOrRoleMemory(string $name, array $service, ?int $accountMemoryMb): string
    {
        $engine = SidecarEngine::resolve($name, $service);
        $catalogued = $engine === null ? null : SidecarEngine::memoryLimitFor($engine);
        if ($catalogued !== null) {
            return $catalogued;
        }

        if ($accountMemoryMb !== null && $accountMemoryMb > 0) {
            return self::underAccountCeiling($accountMemoryMb).'m';
        }

        return in_array(strtolower($name), self::CAPPED_APPLICATION_ROLES, true)
            ? self::CAPPED_ROLE_MEMORY
            : self::DEFAULT_MEMORY;
    }

    /**
     * $limit, or twice the JVM heap the service's environment pins when that is more: an OpenSearch with
     * `-Xmx1g` in a 384m container is killed (exit 137) before it answers its health check.
     *
     * @param  array<string, mixed>  $service
     */
    private static function withRoomForPinnedHeap(string $limit, array $service): string
    {
        $heapMb = self::pinnedHeapMb($service);
        $limitMb = self::toMegabytes($limit);
        if ($heapMb === null || $limitMb === null || $heapMb * self::PINNED_HEAP_FACTOR <= $limitMb) {
            return $limit;
        }

        return ($heapMb * self::PINNED_HEAP_FACTOR).'m';
    }

    /**
     * The largest `-Xmx` in the service's environment, as Compose resolves it unset; null when none.
     *
     * @param  array<string, mixed>  $service
     */
    private static function pinnedHeapMb(array $service): ?int
    {
        $largest = null;
        foreach (SidecarCredentials::environmentMap($service['environment'] ?? null) as $value) {
            if (preg_match_all(self::PINNED_HEAP_PATTERN, EnvVarDefault::resolve((string) $value), $m) < 1) {
                continue;
            }
            foreach ($m[1] as $size) {
                $mb = self::toMegabytes($size);
                $largest = $mb === null ? $largest : max($largest ?? 0, $mb);
            }
        }

        return $largest;
    }

    /**
     * A size above what the account leaves its services is held to that figure, since the account cgroup caps it anyway.
     *
     * @param  mixed  $limit  a byte count or a Compose size string ("8g")
     * @param  int|null  $accountMemoryMb  the project's own limit, in MB
     * @return mixed
     */
    public static function heldToAccount($limit, ?int $accountMemoryMb)
    {
        if ($accountMemoryMb === null || $accountMemoryMb <= 0) {
            return $limit;
        }
        $limitMb = self::toMegabytes($limit);
        $ceilingMb = self::underAccountCeiling($accountMemoryMb);

        return $limitMb !== null && $limitMb > $ceilingMb ? $ceilingMb.'m' : $limit;
    }

    /**
     * The account's budget less a reserve (an eighth, 64 to 512 MB) for dockerd, the sidecars and page cache,
     * so the application cannot starve the daemon; a very small account still gets the floor.
     */
    private static function underAccountCeiling(int $accountMemoryMb): int
    {
        $reserve = (int) round($accountMemoryMb * self::ACCOUNT_RESERVE_SHARE);
        $reserve = max(self::MIN_ACCOUNT_RESERVE_MB, min(self::MAX_ACCOUNT_RESERVE_MB, $reserve));

        return max(self::MIN_SERVICE_MEMORY_MB, $accountMemoryMb - $reserve);
    }

    /**
     * The V8 heap for a container of this size; uncapped, Node sizes it from the host and is OOM-killed.
     *
     * @param  mixed  $memoryLimit
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
     * The JVM heap for a container of this size, in MB; HotSpot's own default is a quarter of the cgroup,
     * which ran a Maven reactor out of heap.
     *
     * @param  mixed  $memoryLimit
     */
    public static function javaHeapMbFor($memoryLimit): int
    {
        return self::nodeHeapMbFor($memoryLimit);
    }

    /**
     * Parallel cargo jobs for a build container of this size, since a `-sys` crate's C++ gets one
     * ~1.2 GB compiler per job.
     *
     * @param  mixed  $memoryLimit
     */
    public static function cargoJobsFor($memoryLimit, ?int $cpus = null): int
    {
        $limitMb = self::toMegabytes($memoryLimit) ?? self::CARGO_JOB_MEMORY_MB;
        $jobs = max(1, intdiv($limitMb, self::CARGO_JOB_MEMORY_MB));

        return $cpus !== null && $cpus > 0 ? min($cpus, $jobs) : $jobs;
    }

    /**
     * The V8 heap for a build inside the account's own container, or null when there is no ceiling to size
     * it against; a cap larger than the cgroup is worse than none.
     */
    public static function nodeHeapMbForAccount(?int $accountMemoryMb): ?int
    {
        if ($accountMemoryMb === null || $accountMemoryMb <= 0) {
            return null;
        }

        return self::nodeHeapMbFor($accountMemoryMb);
    }

    /**
     * @param  mixed  $limit  a byte count or a Compose size string ("512m")
     */
    public static function toMegabytes($limit): ?int
    {
        if (is_int($limit) || (is_string($limit) && ctype_digit($limit))) {
            return self::bytesToMegabytes((int) $limit);
        }
        if (! is_string($limit) || preg_match(self::SIZE_PATTERN, trim($limit), $m) !== 1) {
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
