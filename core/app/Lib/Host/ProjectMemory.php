<?php

namespace App\Lib\Host;

use App\Exceptions\ProblemException;

/**
 * The memory rules for a project's `memory_limit`:
 *
 *  - every project has one; unset at creation, it is {@see defaultMb()};
 *  - it may not be below {@see MIN_MB}, the least Docker starts a container with,
 *    or {@see CONTAINER_MIN_MB} for a container project, whose account runs its own Docker daemon;
 *  - it may not exceed {@see HostMemory::maxProjectMb()}, the host's RAM less
 *    what is kept for the engine. What is free right now does not matter.
 */
final class ProjectMemory
{
    public const FIELD = 'memory_limit';

    /** Docker refuses anything less: "Minimum memory limit allowed is 6MB". */
    public const MIN_MB = 6;

    /**
     * Measured with a static site: the account's own Docker daemon was killed for memory at 112 MB
     * and deployed at 128 MB under constant reclaim, so a container project gets twice that.
     */
    public const CONTAINER_MIN_MB = 256;

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
     * @return ?array{field: string, code: string, message: string, min_mb?: int, max_mb?: int}
     */
    public static function problem(int $limitMb, ?HostMemory $memory = null, bool $container = false): ?array
    {
        if ($limitMb < self::minMb($container)) {
            return self::tooSmall($limitMb, $container);
        }

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

    /** The least a project of this kind may have. */
    private static function minMb(bool $container): int
    {
        return $container ? self::CONTAINER_MIN_MB : self::MIN_MB;
    }

    /** @return array{field: string, code: string, message: string, min_mb: int} */
    private static function tooSmall(int $limitMb, bool $container): array
    {
        $why = $container
            ? 'The memory limit of a container project must be at least %d MB, the least its own Docker daemon runs a site with (%d MB requested).'
            : 'The memory limit must be at least %d MB, the least Docker starts a container with (%d MB requested).';

        return [
            'field' => self::FIELD,
            'code' => 'memory_limit_too_small',
            'message' => sprintf($why, self::minMb($container), $limitMb),
            'min_mb' => self::minMb($container),
        ];
    }

    /** @throws ProblemException when a project of this kind may not have $limitMb */
    public static function assertFits(int $limitMb, bool $container = false): void
    {
        $problem = self::problem($limitMb, container: $container);
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }
}
