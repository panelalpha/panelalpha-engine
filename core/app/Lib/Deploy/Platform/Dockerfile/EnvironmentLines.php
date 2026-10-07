<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\PlatformStage;
use InvalidArgumentException;

/**
 * `ENV key=value`, one per entry, in the order the caller settled on.
 *
 * `PA_DEPLOY_PHASE` is excluded: it is a runtime value compose passes, and as an
 * `ENV` above the dependency install its switch to `upgrade` invalidated that
 * layer and below -- a Django rebuild of an unchanged commit re-ran `pip install`.
 */
final class EnvironmentLines
{
    /**
     * Values written bare, exactly as before, so an existing Dockerfile and
     * its layer cache do not change. `$` stays: a Dockerfile expands it inside
     * double quotes too, and `PATH=/x:$PATH` relies on that.
     */
    private const BARE = '/^[A-Za-z0-9_.,:\/@%+=~${}-]*$/';

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function of(array $env): array
    {
        $lines = [];
        foreach ($env as $key => $value) {
            if ($key === PlatformStage::PHASE_ENV) {
                continue;
            }
            $lines[] = 'ENV ' . self::pair($key, $value);
        }

        return $lines;
    }

    /**
     * `ARG key=value`, one per entry: a build-time default absent from the
     * resulting image. For `NODE_OPTIONS` that matters: a heap sized for the
     * engine's build container exceeds the account's cgroup, and V8 reaches
     * for it and is OOM-killed. Reaches every process the build spawns.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function buildArgs(array $env): array
    {
        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = 'ARG ' . self::pair($key, $value);
        }

        return $lines;
    }

    /**
     * `key=value`, double-quoted when the value holds anything else. Written
     * bare, `ENV A=x y` fails the build ("can't find = in y") and a newline
     * starts a new Dockerfile instruction.
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
            return $key . '=' . $value;
        }

        return $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
