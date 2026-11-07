<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Compose\ComposeEnvironment;

/**
 * The environment overrides an account carries from one deploy to the next: a deploy adds to them,
 * and an empty value drops a key, as {@see ComposeEnvironment} already reads an empty one.
 */
final class EnvVarOverrides
{
    /**
     * @param  array<string, string>  $stored  what the project already carries
     * @param  array<string, string>  $incoming  what this deploy declared
     * @return array<string, string>
     */
    public static function merge(array $stored, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === '') {
                unset($stored[$key]);

                continue;
            }

            $stored[$key] = $value;
        }

        return $stored;
    }

    /**
     * The overrides to store for a deploy that sent `env_vars`, merged onto what the project carries; null clears all.
     *
     * @param  array<string, string>  $stored
     * @return array<string, string>
     */
    public static function applyIncoming(mixed $incoming, array $stored): array
    {
        if ($incoming === null) {
            return [];
        }

        return self::merge($stored, self::normalize($incoming));
    }

    /**
     * ConvertEmptyStringsToNull turns the documented `""` (remove this key) into null, so null means "".
     *
     * @return array<string, string>
     */
    private static function normalize(mixed $envVars): array
    {
        if (! is_array($envVars)) {
            return [];
        }
        $result = [];
        foreach ($envVars as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if ($value === null) {
                $result[$key] = '';

                continue;
            }
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $result[$key] = (string) $value;
        }

        return $result;
    }
}
