<?php

namespace App\Lib\Deploy\Compose;

/**
 * A compose file's top-level `secrets:` and `configs:`, and which of them its services name: a service keeps
 * its `secrets: [db_password]` only while the file still declares `db_password`.
 *
 * No Laravel dependencies — unit-testable.
 */
final class ComposeFileReferences
{
    /** @var list<string> */
    public const SECTIONS = ['secrets', 'configs'];

    private const SECRETS_DIR = '/run/secrets/';

    /**
     * @param  array<mixed>  $compose
     * @return array<string, array<string, mixed>> section => name => entry
     */
    public static function declared(array $compose): array
    {
        $declared = [];
        foreach (self::SECTIONS as $section) {
            $entries = $compose[$section] ?? null;
            if (is_array($entries)) {
                $declared[$section] = array_filter($entries, static fn ($entry, $name): bool => is_string($name), ARRAY_FILTER_USE_BOTH);
            }
        }

        return $declared;
    }

    /**
     * A service's `secrets:` or `configs:` list as written, short and long form alike.
     *
     * @param  array<string, mixed>  $service
     * @return list<mixed>
     */
    public static function listed(array $service, string $section): array
    {
        $list = $service[$section] ?? null;

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * The declarations the services and the app's own secrets name; only `secrets`/`configs` that are used.
     *
     * @param  array<array-key, mixed>  $services
     * @param  list<mixed>  $appSecrets
     * @param  array<string, array<string, mixed>>  $declared  {@see declared()}
     * @return array<string, array<string, mixed>>
     */
    public static function usedBy(array $services, array $appSecrets, array $declared): array
    {
        $used = [];
        foreach (self::SECTIONS as $section) {
            $names = self::namesIn($services, $section);
            if ($section === 'secrets') {
                $names = [...$names, ...self::names($appSecrets)];
            }
            $entries = array_intersect_key($declared[$section] ?? [], array_flip($names));
            if ($entries !== []) {
                $used[$section] = $entries;
            }
        }

        return $used;
    }

    /**
     * The references of $list whose name $declared still holds.
     *
     * @param  list<mixed>  $list
     * @param  array<string, mixed>  $declared
     * @return list<mixed>
     */
    public static function onlyDeclared(array $list, array $declared): array
    {
        return array_values(array_filter(
            $list,
            static fn (mixed $reference): bool => array_key_exists((string) self::nameOf($reference), $declared)
        ));
    }

    /**
     * $more appended to $list, a name already there not twice.
     *
     * @param  list<mixed>  $list
     * @param  list<mixed>  $more
     * @return list<mixed>
     */
    public static function merged(array $list, array $more): array
    {
        $names = self::names($list);
        foreach ($more as $reference) {
            $name = self::nameOf($reference);
            if ($name !== null && ! in_array($name, $names, true)) {
                $list[] = $reference;
                $names[] = $name;
            }
        }

        return $list;
    }

    /**
     * The secret $service mounts at $path, by name.
     *
     * @param  array<string, mixed>  $service
     */
    public static function secretMountedAt(array $service, string $path): ?string
    {
        foreach (self::listed($service, 'secrets') as $reference) {
            if (self::secretPathOf($reference) === $path) {
                return self::nameOf($reference);
            }
        }

        return null;
    }

    /** Where a `secrets:` entry mounts its secret: `/run/secrets/<name>`, or the long form's `target`. */
    public static function secretPathOf(mixed $reference): ?string
    {
        $name = self::nameOf($reference);
        $target = is_array($reference) && is_string($reference['target'] ?? null) ? $reference['target'] : $name;
        if ($name === null || ! is_string($target)) {
            return null;
        }

        return str_starts_with($target, '/') ? $target : self::SECRETS_DIR.$target;
    }

    /**
     * $service without its `secrets:` entries for $names, the key gone with the last one.
     *
     * @param  array<string, mixed>  $service
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public static function withoutSecrets(array $service, array $names): array
    {
        if ($names === [] || ! is_array($service['secrets'] ?? null)) {
            return $service;
        }
        $kept = self::withoutNames(self::listed($service, 'secrets'), $names);
        if ($kept === []) {
            unset($service['secrets']);
        } else {
            $service['secrets'] = $kept;
        }

        return $service;
    }

    /**
     * $list without the references to $names.
     *
     * @param  list<mixed>  $list
     * @param  list<string>  $names
     * @return list<mixed>
     */
    public static function withoutNames(array $list, array $names): array
    {
        return array_values(array_filter(
            $list,
            static fn (mixed $reference): bool => ! in_array(self::nameOf($reference), $names, true)
        ));
    }

    /** `db_password`, or `{source: db_password, target: …}`'s source. */
    public static function nameOf(mixed $reference): ?string
    {
        $name = is_array($reference) ? ($reference['source'] ?? null) : $reference;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  array<array-key, mixed>  $services
     * @return list<string>
     */
    private static function namesIn(array $services, string $section): array
    {
        $names = [];
        foreach ($services as $service) {
            if (is_array($service)) {
                $names = [...$names, ...self::names(self::listed($service, $section))];
            }
        }

        return $names;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<string>
     */
    private static function names(array $list): array
    {
        return array_values(array_filter(array_map(self::nameOf(...), $list), 'is_string'));
    }
}
