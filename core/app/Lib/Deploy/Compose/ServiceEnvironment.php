<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\SidecarCredentials;

/**
 * Compose accepts a service's `environment:` as a map or as a list of
 * `KEY=value` lines; an edit must preserve the form the project wrote.
 */
final class ServiceEnvironment
{
    /**
     * @param  mixed  $environment
     */
    public static function hasKey($environment, string $key): bool
    {
        return is_array($environment)
            && array_key_exists($key, SidecarCredentials::environmentMap($environment));
    }

    /**
     * @param  mixed  $environment
     * @param  array<string, string>  $defaults
     * @return array<int|string, mixed>
     */
    public static function withDefaults($environment, array $defaults = []): array
    {
        if (! is_array($environment) || $environment === []) {
            return $defaults;
        }

        return self::isList($environment)
            ? self::appendMissingLines($environment, $defaults)
            : $environment + $defaults;
    }

    /**
     * By key type, since an `=` in a value proves nothing (`- POSTGRES_PASSWORD`). Empty is neither:
     * callers merge defaults into it as a map.
     *
     * @param  array<mixed>  $environment
     */
    public static function isList(array $environment): bool
    {
        return $environment !== [] && array_is_list($environment);
    }

    /**
     * @param  list<mixed>  $environment
     * @param  array<string, string>  $defaults
     * @return list<mixed>
     */
    private static function appendMissingLines(array $environment, array $defaults): array
    {
        $declared = [];
        foreach ($environment as $line) {
            if (is_string($line) && str_contains($line, '=')) {
                $declared[explode('=', $line, 2)[0]] = true;
            }
        }

        foreach ($defaults as $key => $value) {
            if (! isset($declared[$key])) {
                $environment[] = $key.'='.$value;
            }
        }

        return $environment;
    }
}
