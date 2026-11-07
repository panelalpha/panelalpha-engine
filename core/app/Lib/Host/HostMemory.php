<?php

namespace App\Lib\Host;

/**
 * The host's RAM and what is kept back for the engine, in MB. What is left is the most one project may
 * have, and what a project created without a limit gets.
 */
final class HostMemory
{
    /** What `DEPLOY_ENGINE_MEMORY` is when unset. */
    public const ENGINE_DEFAULT_MB = 512;

    /**
     * @param  int  $totalMb  MemTotal
     * @param  int  $engineMb  DEPLOY_ENGINE_MEMORY: kept back for the engine
     */
    public function __construct(
        public readonly int $totalMb,
        public readonly int $engineMb = self::ENGINE_DEFAULT_MB,
    ) {}

    /** The largest `memory_limit` one project may have. */
    public function maxProjectMb(): int
    {
        return max(0, $this->totalMb - $this->engineMb);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'total_mb' => $this->totalMb,
            'engine_mb' => $this->engineMb,
            'max_project_mb' => $this->maxProjectMb(),
        ];
    }
}
