<?php

namespace App\Lib\Host;

use App\Exceptions\ProblemException;

/**
 * The memory rules for a project's `memory_limit`:
 *
 *  - every project has one; unset at creation, it is {@see defaultMb()};
 *  - it may not exceed {@see HostMemory::maxProjectMb()};
 *  - a project is only created when the host has that much free right now.
 */
final class ProjectMemory
{
    public const FIELD = 'memory_limit';

    public const DEFAULT_MB = 2048;

    /** `DEPLOY_PROJECT_MEMORY_DEFAULT`, 2048 unless the operator says otherwise. */
    public static function defaultMb(): int
    {
        // Config is not loaded outside the app, e.g. in plain unit tests.
        $configured = app()->bound('config')
            ? (int) config('deploy.project_memory_default', self::DEFAULT_MB)
            : self::DEFAULT_MB;

        return $configured > 0 ? $configured : self::DEFAULT_MB;
    }

    /** The limit a project runs with: its own, or the default for one made before limits were required. */
    public static function resolve(?int $limitMb): int
    {
        return $limitMb !== null && $limitMb > 0 ? $limitMb : self::defaultMb();
    }

    /**
     * Why a new project of $limitMb cannot be created now, or null when it can.
     *
     * @return ?array{field: string, code: string, message: string, max_mb: int, free_mb: int}
     */
    public static function creationProblem(int $limitMb, ?HostMemory $memory = null): ?array
    {
        $memory ??= HostMemoryProbe::current();

        return self::tooLarge($limitMb, $memory) ?? self::notFree($limitMb, $memory);
    }

    /**
     * Why an existing project may not be given $limitMb, or null when it may.
     *
     * @return ?array{field: string, code: string, message: string, max_mb: int, free_mb: int}
     */
    public static function changeProblem(int $limitMb, ?HostMemory $memory = null): ?array
    {
        return self::tooLarge($limitMb, $memory ?? HostMemoryProbe::current());
    }

    /** @throws ProblemException when a new project of $limitMb cannot be created now */
    public static function assertCanCreate(int $limitMb): void
    {
        $problem = self::creationProblem($limitMb);
        if ($problem !== null) {
            throw ProblemException::of([$problem]);
        }
    }

    /** @return ?array{field: string, code: string, message: string, max_mb: int, free_mb: int} */
    private static function tooLarge(int $limitMb, HostMemory $memory): ?array
    {
        if ($limitMb <= $memory->maxProjectMb()) {
            return null;
        }

        return self::problem('memory_limit_too_large', sprintf(
            'The memory limit may not be greater than %d MB, the memory projects have on this server (%d MB requested).',
            $memory->maxProjectMb(),
            $limitMb
        ), $memory);
    }

    /** @return ?array{field: string, code: string, message: string, max_mb: int, free_mb: int} */
    private static function notFree(int $limitMb, HostMemory $memory): ?array
    {
        if ($limitMb <= $memory->freeForProjectsMb()) {
            return null;
        }

        return self::problem('not_enough_memory', sprintf(
            'This server has %d MB free for a new project and it needs %d MB. Give it a lower memory_limit, or free memory first.',
            $memory->freeForProjectsMb(),
            $limitMb
        ), $memory);
    }

    /** @return array{field: string, code: string, message: string, max_mb: int, free_mb: int} */
    private static function problem(string $code, string $message, HostMemory $memory): array
    {
        return [
            'field' => self::FIELD,
            'code' => $code,
            'message' => $message,
            'max_mb' => $memory->maxProjectMb(),
            'free_mb' => $memory->freeForProjectsMb(),
        ];
    }
}
