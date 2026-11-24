<?php

namespace App\Lib\Deploy\Compose;

/**
 * A secret only a datastore's settled `*_FILE` read ({@see SecretFileEnvironment} could not) leaves it; one Compose
 * cannot fill (an unset `environment:`, `external:`, no `file:`) leaves every service, since Compose refuses them.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SettledSecretMounts
{
    /** A secret path inside a longer path or name is not that path. */
    private const PATH_BOUNDARY = '[A-Za-z0-9._\/-]';

    /**
     * The secrets $before read through a `*_FILE` variable $after no longer has.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function settledBetween(array $before, array $after): array
    {
        $remaining = self::environmentOf($after);
        $names = [];
        foreach (self::environmentOf($before) as $key => $value) {
            if (! str_ends_with((string) $key, '_FILE') || ! is_string($value) || array_key_exists($key, $remaining)) {
                continue;
            }
            $name = ComposeFileReferences::secretMountedAt($before, trim($value));
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<array-key, mixed>  $services  the kept services, settled
     * @param  list<mixed>  $appSecrets  the secrets the app takes over
     * @param  array<array-key, list<string>>  $settled  service => {@see settledBetween()}
     * @param  array<array-key, mixed>  $declared  the top-level `secrets:`
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @return array{0: array<array-key, mixed>, 1: list<mixed>} the services, the app's secrets
     */
    public static function unmounted(array $services, array $appSecrets, array $settled, array $declared, array $env): array
    {
        $unprovided = array_values(array_filter(
            array_unique(array_merge(...array_values($settled))),
            static fn (string $name): bool => ! self::isProvided($declared[$name] ?? null, $env)
        ));
        foreach ($services as $name => $service) {
            if (is_array($service)) {
                $unused = self::namedNowhereElse($service, $settled[$name] ?? []);
                $services[$name] = ComposeFileReferences::withoutSecrets($service, [...$unprovided, ...$unused]);
            }
        }

        return [$services, ComposeFileReferences::withoutNames($appSecrets, $unprovided)];
    }

    /**
     * A `file:` (one the checkout leaves out is written), or an `environment:` variable that cannot end up unset.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    private static function isProvided(mixed $declaration, array $env): bool
    {
        if (! is_array($declaration)) {
            return false;
        }
        if (is_string($declaration['file'] ?? null) && trim($declaration['file']) !== '') {
            return true;
        }
        $variable = $declaration['environment'] ?? null;

        return is_string($variable) && array_key_exists($variable, $env) && ! in_array(null, (array) $env[$variable], true);
    }

    /**
     * Of $names, the secrets $service names nowhere but in its `secrets:`.
     *
     * @param  array<string, mixed>  $service
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function namedNowhereElse(array $service, array $names): array
    {
        $rest = $service;
        unset($rest['secrets']);
        $strings = self::stringsIn($rest);

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => ! self::anyNames($strings, self::pathOf($service, $name))
        ));
    }

    /** @param  array<string, mixed>  $service */
    private static function pathOf(array $service, string $name): ?string
    {
        foreach (ComposeFileReferences::listed($service, 'secrets') as $reference) {
            if (ComposeFileReferences::nameOf($reference) === $name) {
                return ComposeFileReferences::secretPathOf($reference);
            }
        }

        return null;
    }

    /** @param  list<string>  $strings */
    private static function anyNames(array $strings, ?string $path): bool
    {
        if ($path === null) {
            return false;
        }
        $pattern = '/(?<!'.self::PATH_BOUNDARY.')'.preg_quote($path, '/').'(?!'.self::PATH_BOUNDARY.')/';
        foreach ($strings as $string) {
            if (preg_match($pattern, $string) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function stringsIn(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $key => $item) {
            $strings = [...$strings, ...(is_string($key) ? [$key] : []), ...self::stringsIn($item)];
        }

        return $strings;
    }

    /**
     * `environment:` as a map, the `- KEY=value` form included.
     *
     * @param  array<string, mixed>  $service
     * @return array<array-key, mixed>
     */
    private static function environmentOf(array $service): array
    {
        $environment = $service['environment'] ?? null;
        if (! is_array($environment) || ! array_is_list($environment)) {
            return is_array($environment) ? $environment : [];
        }
        $map = [];
        foreach ($environment as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $parts = explode('=', $entry, 2);
                $map[trim($parts[0])] = $parts[1] ?? null;
            }
        }

        return $map;
    }
}
