<?php

namespace App\Lib\Host;

use App\Exceptions\ProblemException;

/**
 * The memory rules for a project's `memory_limit`:
 *
 *  - every project has one; unset at creation, it is {@see defaultMb()};
 *  - it may not exceed {@see HostMemory::maxProjectMb()}, the host's RAM less
 *    what is kept for the engine. What is free right now does not matter.
 */
final class ProjectMemory
{
    public const FIELD = 'memory_limit';

    /**
     * `DEPLOY_PROJECT_MEMORY_DEFAULT` when set, else the most a project may
     * have. Never above that maximum.
     */
    public static function defaultMb(?HostMemory $memory = null): int
    {
        $max = ($memory ?? HostMemoryProbe::current())->maxProjectMb();
        // Config is not loaded outside the app, e.g. in plain unit tests.
        $configured = app()->bound('config') ? (int) config('deploy.project_memory_default', 0) : 0;

        return $configured > 0 ? min($configured, $max) : $max;
    }

    /** The limit a project runs with: its own, or the default for one made before limits were required. */
    public static function resolve(?int $limitMb): int
    {
        return $limitMb !== null && $limitMb > 0 ? $limitMb : self::defaultMb();
    }

    /**
     * Why a project may not have $limitMb, or null when it may.
     *
     * @return ?array{field: string, code: string, message: string, max_mb: int}
     */
    public static function problem(int $limitMb, ?HostMemory $memory = null): ?array
    {
        $memory ??= HostMemoryProbe::current();
        if ($limitMb <= $memory->maxProjectMb()) {
            return null;
        }

        return [
            'field' => self::FIELD,
            'code' => 'memory_limit_too_large',
            'message' => sprintf(
                'The memory limit may not be greater than %d MB: this server has %d MB and %d MB is kept for the engine (%d MB requested).',
                $memory->maxProjectMb(),
                $memory->totalMb,
                $memory->engineMb,
                $limitMb
            ),
            'max_mb' => $memory->maxProjectMb(),
        ];
    }

    /** @throws ProblemException when a project may not have $limitMb */
    public static function assertFits(int $limitMb): void
    {
        $problem = self::problem($limitMb);
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }
}
