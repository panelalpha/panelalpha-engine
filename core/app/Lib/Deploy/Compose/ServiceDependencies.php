<?php

namespace App\Lib\Deploy\Compose;

/**
 * References to services that have been dropped, which Compose refuses to start a stack with:
 * `depends_on`, `links`, `network_mode: service:x` and `volumes_from`.
 */
final class ServiceDependencies
{
    /**
     * The keys that name another service and must be pruned with it.
     *
     * @var list<string>
     */
    private const REFERENCE_KEYS = ['depends_on', 'links'];

    /**
     * Without the service whose network it shared, a service joins the stack's own.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, true>  $dropped  lowercase service names
     * @return array<string, mixed>
     */
    public static function withoutDropped(array $service, array $dropped): array
    {
        if ($dropped === []) {
            return $service;
        }

        foreach (self::REFERENCE_KEYS as $key) {
            $service = self::withoutDroppedIn($service, $key, $dropped);
        }
        $network = self::networkServiceOf($service);
        if ($network !== null && isset($dropped[strtolower($network)])) {
            unset($service['network_mode']);
        }

        return self::withoutDroppedVolumesFrom($service, $dropped);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, true>  $dropped
     * @return array<string, mixed>
     */
    private static function withoutDroppedVolumesFrom(array $service, array $dropped): array
    {
        if (is_array($service['volumes_from'] ?? null)) {
            $service['volumes_from'] = array_values(array_filter(
                $service['volumes_from'],
                static fn ($ref): bool => ! is_string($ref)
                    || str_starts_with($ref, 'container:')
                    || ! isset($dropped[strtolower(self::serviceIn($ref))])
            ));
            if ($service['volumes_from'] === []) {
                unset($service['volumes_from']);
            }
        }

        return $service;
    }

    /**
     * The services $service names through `depends_on`, `links`, `network_mode: service:x` or `volumes_from`.
     *
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    public static function namesIn(array $service): array
    {
        $names = [];
        foreach (self::REFERENCE_KEYS as $key) {
            $refs = $service[$key] ?? null;
            if (! is_array($refs)) {
                continue;
            }
            foreach (self::isStringList($refs) ? $refs : array_keys($refs) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $names[] = self::serviceIn($ref);
                }
            }
        }
        $network = self::networkServiceOf($service);
        if ($network !== null) {
            $names[] = $network;
        }
        foreach (is_array($service['volumes_from'] ?? null) ? $service['volumes_from'] : [] as $ref) {
            if (is_string($ref) && ! str_starts_with($ref, 'container:')) {
                $names[] = self::serviceIn($ref);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Points every reference to $from at $to, keeping a `links` alias.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    public static function renamed(array $service, string $from, string $to): array
    {
        $matches = static fn ($ref): bool => is_string($ref) && strcasecmp(self::serviceIn($ref), $from) === 0;
        foreach (self::REFERENCE_KEYS as $key) {
            $refs = $service[$key] ?? null;
            if (! is_array($refs)) {
                continue;
            }
            $service[$key] = self::renamedReferences($refs, $matches, $to);
        }
        $network = self::networkServiceOf($service);
        if ($network !== null && strcasecmp($network, $from) === 0) {
            $service['network_mode'] = 'service:'.$to;
        }
        if (is_array($service['volumes_from'] ?? null)) {
            $service['volumes_from'] = array_map(
                static fn ($ref) => is_string($ref) && ! str_starts_with($ref, 'container:') && $matches($ref)
                    ? $to.substr($ref, strlen(self::serviceIn($ref)))
                    : $ref,
                $service['volumes_from']
            );
        }

        return $service;
    }

    /**
     * @param  array<array-key, mixed>  $refs
     * @return array<array-key, mixed>
     */
    private static function renamedReferences(array $refs, \Closure $matches, string $to): array
    {
        if (self::isStringList($refs)) {
            return array_map(
                static fn ($ref) => $matches($ref) ? $to.substr($ref, strlen(self::serviceIn($ref))) : $ref,
                $refs
            );
        }
        $renamed = [];
        foreach ($refs as $name => $condition) {
            $renamed[$matches((string) $name) ? $to : $name] = $condition;
        }

        return $renamed;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function networkServiceOf(array $service): ?string
    {
        $mode = $service['network_mode'] ?? null;
        if (! is_string($mode) || ! str_starts_with(trim($mode), 'service:')) {
            return null;
        }

        return trim(substr(trim($mode), strlen('service:')));
    }

    /**
     * `links` entries may be `name` or `name:alias`; the alias goes with the dropped service.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, true>  $dropped
     * @return array<string, mixed>
     */
    private static function withoutDroppedIn(array $service, string $key, array $dropped): array
    {
        if (! isset($service[$key]) || ! is_array($service[$key])) {
            return $service;
        }

        $kept = self::isStringList($service[$key])
            ? self::keepListed($service[$key], $dropped)
            : self::keepKeyed($service[$key], $dropped);

        if ($kept === []) {
            unset($service[$key]);

            return $service;
        }
        $service[$key] = $kept;

        return $service;
    }

    /**
     * @param  list<mixed>  $dependencies
     * @param  array<string, true>  $dropped
     * @return list<string>
     */
    private static function keepListed(array $dependencies, array $dropped): array
    {
        return array_values(array_filter(
            $dependencies,
            static fn ($name): bool => is_string($name)
                && ! isset($dropped[strtolower(self::serviceIn($name))])
        ));
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @param  array<string, true>  $dropped
     * @return array<string, mixed>
     */
    private static function keepKeyed(array $dependencies, array $dropped): array
    {
        foreach (array_keys($dependencies) as $name) {
            if (isset($dropped[strtolower((string) $name)])) {
                unset($dependencies[$name]);
            }
        }

        return $dependencies;
    }

    /** `name` or `name:alias` — the service is the part before the colon. */
    private static function serviceIn(string $reference): string
    {
        $colon = strpos($reference, ':');

        return $colon === false ? $reference : substr($reference, 0, $colon);
    }

    /**
     * @param  array<mixed>  $items
     */
    private static function isStringList(array $items): bool
    {
        return $items === [] || (isset($items[0]) && is_string($items[0]));
    }
}
