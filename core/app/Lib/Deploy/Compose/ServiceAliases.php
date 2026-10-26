<?php

namespace App\Lib\Deploy\Compose;

/**
 * Network aliases for `app`. A customer's proxy config names the service the
 * engine replaced (`fastcgi_pass php:9000`, `proxy_pass` to an `upstream`), and
 * removing the reference would leave a 502 that reads as the app's fault.
 */
final class ServiceAliases
{
    /**
     * The aliases a decision carries, ready to render. Compose accepts an
     * unresolvable alias happily, so a bad one turns a loud start-up failure
     * into a silent one at request time.
     *
     * @param  array<string, mixed>  $decision
     * @return list<string>
     */
    public static function fromDecision(array $decision): array
    {
        return self::normalised($decision['app_aliases'] ?? null);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    public static function normalised($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $aliases = [];
        $seen = [];
        foreach ($value as $alias) {
            $alias = trim((string) $alias);
            $key = strtolower($alias);
            if ($alias === '' || $key === GeneratedCompose::APP_SERVICE || isset($seen[$key])) {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/', $alias) === 1) {
                $seen[$key] = true;
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * A service's `networks:`, with `$aliases` added to its default network.
     * Accepts both the list form and the map form. A `default` entry needs no
     * top-level `networks:` declaration, so no extra key is written.
     *
     * @param  mixed  $networks  the service's existing `networks:` value, or null
     * @param  list<string>  $aliases
     * @return array<string, mixed>
     */
    public static function withAliases($networks, array $aliases): array
    {
        $networks = is_array($networks) ? $networks : [];
        if (array_is_list($networks)) {
            $networks = array_fill_keys(array_map('strval', $networks), null);
        }

        $current = $networks['default'] ?? null;
        $attributes = is_array($current) ? $current : [];
        $existing = is_array($attributes['aliases'] ?? null) ? $attributes['aliases'] : [];
        $attributes['aliases'] = array_values(array_unique(array_merge(
            array_map('strval', $existing),
            $aliases
        )));
        $networks['default'] = $attributes;

        return $networks;
    }
}
