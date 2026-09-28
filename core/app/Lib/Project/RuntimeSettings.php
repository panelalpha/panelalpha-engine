<?php

namespace App\Lib\Project;

/**
 * A project's PHP-FPM pool, LSPHP and Redis settings, read from the text the
 * API stores for each (one `key<separator>value` per line) and checked
 * against what the runtime will accept. Anything unset or unusable falls
 * back to the defaults below.
 */
final class RuntimeSettings
{
    /** @return array<string,string> */
    public static function phpFpmPool(mixed $raw): array
    {
        $default = [
            'pm' => 'dynamic',
            'pm.max_children' => '5',
            'pm.start_servers' => '2',
            'pm.min_spare_servers' => '1',
            'pm.max_spare_servers' => '3',
            'pm.max_requests' => '0',
        ];
        if (empty($raw) || !is_string($raw)) {
            return $default;
        }

        $parsed = self::lines($raw, ' = ');

        $validated = $default;
        if (
            array_key_exists('pm', $parsed)
            && in_array($parsed['pm'], ['static', 'dynamic', 'ondemand'])
        ) {
            $validated['pm'] = $parsed['pm'];
        }
        foreach (['pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests'] as $key) {
            if (array_key_exists($key, $parsed) && filter_var($parsed[$key], FILTER_VALIDATE_INT) !== false) {
                $validated[$key] = $parsed[$key];
            }
        }

        // Keys not checked above pass through as written.
        return array_merge($parsed, $validated);
    }

    /** @return array<string,string> */
    public static function lsphp(mixed $raw): array
    {
        $default = [
            'PHP_LSAPI_CHILDREN' => '35',
            'PHP_LSAPI_MAX_REQUESTS' => '5000',
        ];
        if (empty($raw) || !is_string($raw)) {
            return $default;
        }

        $parsed = self::lines($raw, '=');

        $validated = $default;
        foreach (['PHP_LSAPI_CHILDREN', 'PHP_LSAPI_MAX_REQUESTS'] as $key) {
            if (array_key_exists($key, $parsed) && filter_var($parsed[$key], FILTER_VALIDATE_INT) !== false) {
                $validated[$key] = $parsed[$key];
            }
        }

        // Keys not checked above pass through as written.
        return array_merge($parsed, $validated);
    }

    /**
     * Unlike the two above, only the allowed keys come back.
     *
     * @return array<string,string>
     */
    public static function redis(mixed $raw): array
    {
        $default = [
            'maxmemory' => '128mb',
            'maxmemory-policy' => 'allkeys-lru',
            'maxmemory-samples' => '5',
            'save' => '""',
            'hz' => '10',
            'timeout' => '0',
            'lazyfree-lazy-eviction' => 'no',
            'lazyfree-lazy-expire' => 'no',
            'activedefrag' => 'no',
            'lfu-log-factor' => '10',
            'lfu-decay-time' => '1',
        ];
        if (empty($raw) || !is_string($raw)) {
            return $default;
        }

        $parsed = self::lines($raw, ' ');

        $allowedKeys = [
            'maxmemory',
            'maxmemory-policy',
            'save',
            'hz',
            'timeout',
            'maxmemory-samples',
            'lazyfree-lazy-eviction',
            'lazyfree-lazy-expire',
            'activedefrag',
            'lfu-decay-time',
            'lfu-log-factor',
        ];

        $allowedMaxmemoryPolicies = [
            'noeviction',
            'allkeys-lru',
            'volatile-lru',
            'allkeys-random',
            'volatile-random',
            'volatile-ttl',
            'allkeys-lfu',
            'volatile-lfu',
        ];

        $validated = $default;
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $parsed)) {
                continue;
            }
            $value = $parsed[$key];
            if ($key === 'maxmemory-policy') {
                if (in_array($value, $allowedMaxmemoryPolicies)) {
                    $validated[$key] = $value;
                }
                continue;
            }
            if (in_array($key, ['hz', 'timeout', 'maxmemory-samples', 'lfu-decay-time', 'lfu-log-factor'])) {
                if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                    $validated[$key] = $value;
                }
                continue;
            }
            if (in_array($key, ['lazyfree-lazy-eviction', 'lazyfree-lazy-expire', 'activedefrag'])) {
                if (in_array($value, ['yes', 'no'])) {
                    $validated[$key] = $value;
                }
                continue;
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * `key<separator>value` per line, trimmed; a line without the separator
     * is skipped and a repeated key keeps its last value.
     *
     * @return array<string,string>
     */
    private static function lines(string $raw, string $separator): array
    {
        $parsed = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode($separator, trim($line), 2);
            if (count($parts) < 2) {
                continue;
            }
            $parsed[$parts[0]] = $parts[1];
        }

        return $parsed;
    }
}
