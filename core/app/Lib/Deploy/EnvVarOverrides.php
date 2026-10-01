<?php

namespace App\Lib\Deploy;

/**
 * The environment overrides an account carries from one deploy to the next.
 *
 * They are stored on the project, not sent afresh with every deploy, so a
 * deploy that names one of them is saying "and this one too", not "these are
 * the only ones there have ever been". Replacing the stored map instead cost
 * a Symfony deploy its `APP_SECRET`: a rebuild sent `{"APP_ENV":"prod"}` to
 * correct one variable and the application came up on an empty secret it had
 * been given two deploys earlier.
 *
 * Removing one therefore needs a spelling of its own, and the empty string
 * already has that meaning everywhere these values are read — an empty
 * override is no override, in `.env` ({@see \App\\System\\Project\Dind\ProjectEnvironment})
 * and in the generated compose environment
 * ({@see \App\Lib\Deploy\Compose\ComposeEnvironment}) alike. So an empty value
 * drops the key rather than being stored as a variable that does nothing.
 *
 * Clearing every override at once is `env_vars: null`, which the controllers
 * handle: it is a different statement from "here are some overrides".
 *
 * No Laravel dependencies — unit-testable.
 */
final class EnvVarOverrides
{
    /**
     * @param array<string, string> $stored what the project already carries
     * @param array<string, string> $incoming what this deploy declared
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
     * The overrides to store for a deploy that sent `env_vars`, merged onto
     * what the project already carries. An explicit null clears them all.
     *
     * @param array<string, string> $stored
     * @return array<string, string>
     */
    public static function applyIncoming(mixed $incoming, array $stored): array
    {
        if ($incoming === null) {
            return [];
        }

        return self::merge($stored, self::normalize($incoming));
    }

    /** @return array<string, string> */
    private static function normalize(mixed $envVars): array
    {
        if (!is_array($envVars)) {
            return [];
        }
        $result = [];
        foreach ($envVars as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            // ConvertEmptyStringsToNull turns the documented `""` (remove this key) into null.
            if ($value === null) {
                $result[$key] = '';

                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $result[$key] = (string) $value;
        }

        return $result;
    }
}
