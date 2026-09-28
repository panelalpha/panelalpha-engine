<?php

namespace App\Lib\Host;

/**
 * One reading of the host's memory, in MB: what the engine uses, what the
 * system around it uses, what projects use, and how much projects may have.
 *
 * Projects share a pool. By default the pool is everything the engine and the
 * system do not use, minus a small headroom; `DEPLOY_PROJECTS_MEMORY` sets it
 * instead (16 GB on a 32 GB host, say). A project may not be larger than the
 * pool, and one is only created when the host has its limit free right now.
 */
final class HostMemory
{
    /** Kept free for the engine to grow during a deploy; core rose ~70 MB under three. */
    public const HEADROOM_MB = 256;

    /**
     * @param int $totalMb MemTotal
     * @param int $availableMb MemAvailable: free plus what the kernel can reclaim
     * @param int $engineMb the engine's containers, memory they cannot hand back
     * @param int $projectsMb every project's container, the same way
     * @param ?int $configuredPoolMb DEPLOY_PROJECTS_MEMORY, when set
     */
    public function __construct(
        public readonly int $totalMb,
        public readonly int $availableMb,
        public readonly int $engineMb,
        public readonly int $projectsMb,
        public readonly ?int $configuredPoolMb = null,
    ) {
    }

    /** Kernel, Docker and the OS: everything in use that is neither engine nor project. */
    public function systemMb(): int
    {
        return max(0, $this->totalMb - $this->availableMb - $this->engineMb - $this->projectsMb);
    }

    /** What all projects together may use: configured, or what the engine and system leave. */
    public function projectsPoolMb(): int
    {
        return $this->configuredPoolMb
            ?? max(0, $this->totalMb - $this->engineMb - $this->systemMb() - self::HEADROOM_MB);
    }

    /** The largest `memory_limit` one project may have. */
    public function maxProjectMb(): int
    {
        return $this->projectsPoolMb();
    }

    /**
     * What a new project may take right now: what is left of the pool, and
     * never more than the host has free.
     */
    public function freeForProjectsMb(): int
    {
        return max(0, min(
            $this->projectsPoolMb() - $this->projectsMb,
            $this->availableMb - self::HEADROOM_MB
        ));
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'total_mb' => $this->totalMb,
            'engine_mb' => $this->engineMb,
            'system_mb' => $this->systemMb(),
            'projects_used_mb' => $this->projectsMb,
            'headroom_mb' => self::HEADROOM_MB,
            'projects_pool_mb' => $this->projectsPoolMb(),
            'projects_pool_source' => $this->configuredPoolMb === null ? 'measured' : 'configured',
            'free_for_projects_mb' => $this->freeForProjectsMb(),
            'max_project_mb' => $this->maxProjectMb(),
        ];
    }
}
