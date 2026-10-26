<?php

namespace App\System\Project\Dind;

/**
 * How an account container is isolated from the engine around it.
 *
 * Lib tree keeps a parallel copy (unchanged until deletion).
 */
final class AccountRuntime
{
    public const SYSBOX = 'sysbox-runc';

    public const PRIVILEGED = 'privileged';

    public static function configured(): string
    {
        if (! function_exists('config')) {
            return self::SYSBOX;
        }
        try {
            $configured = config('env.DIND_RUNTIME');
        } catch (\Throwable $e) {
            return self::SYSBOX;
        }

        return is_string($configured) && strtolower(trim($configured)) === self::PRIVILEGED
            ? self::PRIVILEGED
            : self::SYSBOX;
    }

    public static function isSysbox(): bool
    {
        return self::configured() === self::SYSBOX;
    }

    public static function composeIsolation(): string
    {
        return self::isSysbox()
            ? 'runtime: '.self::SYSBOX
            : 'privileged: true';
    }

    /**
     * @return list<string>
     */
    public static function dockerRunArgs(): array
    {
        return self::isSysbox()
            ? ['--runtime', self::SYSBOX]
            : ['--privileged'];
    }
}
