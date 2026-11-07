<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\PlatformStage;
use InvalidArgumentException;

/**
 * `ENV key=value`, one per entry, in the caller's order; `PA_DEPLOY_PHASE` is left to compose,
 * since as an `ENV` its switch to `upgrade` invalidated the dependency-install layer.
 */
final class EnvironmentLines
{
    /**
     * Values written bare, exactly as before, so existing layer caches hold; `$` stays because
     * a Dockerfile expands it inside double quotes too (`PATH=/x:$PATH`).
     */
    private const BARE = '/^[A-Za-z0-9_.,:\/@%+=~${}-]*$/';

    /**
     * @param  array<string, string>  $env
     * @return list<string>
     */
    public static function of(array $env): array
    {
        $lines = [];
        foreach ($env as $key => $value) {
            if ($key === PlatformStage::PHASE_ENV) {
                continue;
            }
            $lines[] = 'ENV '.self::pair($key, $value);
        }

        return $lines;
    }

    /**
     * `ARG key=value`, one per entry: a build-time default absent from the image, so a
     * `NODE_OPTIONS` heap sized for the build container does not OOM the running app.
     *
     * @param  array<string, string>  $env
     * @return list<string>
     */
    public static function buildArgs(array $env): array
    {
        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = 'ARG '.self::pair($key, $value);
        }

        return $lines;
    }

    /**
     * `key=value`, double-quoted when the value holds anything else: bare, `ENV A=x y` fails
     * the build and a newline starts a new instruction.
     */
    private static function pair(string $key, string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $key) !== 1) {
            throw new InvalidArgumentException("'{$key}' cannot be written to a Dockerfile as a variable name.");
        }
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException("The value of {$key} spans more than one line, which a Dockerfile cannot hold.");
        }
        if (preg_match(self::BARE, $value) === 1) {
            return $key.'='.$value;
        }

        return $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
