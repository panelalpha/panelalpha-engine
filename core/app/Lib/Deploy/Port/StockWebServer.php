<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Sidecar\ComposeService;

/**
 * A compose service that runs a web server's stock image with nothing of the project's: no build, command,
 * config, dependency or bind mount, and named volumes only where the server keeps config, logs or cache.
 * All it can answer with is the image's own default page.
 */
final class StockWebServer
{
    /** Image families whose stock image serves a default page. */
    private const FAMILIES = ['nginx', 'nginx-unprivileged', 'httpd', 'caddy', 'openresty'];

    /** Keys any one of which gives the server something of the project's to serve. */
    private const PROJECT_KEYS = ['build', 'command', 'entrypoint', 'configs', 'secrets', 'volumes_from', 'depends_on', 'links'];

    /** Kernel filesystems the hosting layer binds into every service; never anything the server serves. */
    private const SYSTEM_TARGETS = ['/proc', '/sys', '/dev'];

    /** Mount targets that hold config, logs or cache, never content to serve. */
    private const NON_CONTENT_TARGETS = [
        '/etc', '/var/log', '/var/cache', '/var/run', '/run', '/tmp',
        '/usr/local/apache2/conf', '/usr/local/apache2/logs',
        '/usr/local/openresty/nginx/conf', '/usr/local/openresty/nginx/logs',
    ];

    /**
     * @param  array<string, mixed>  $service
     */
    public static function servesOnlyItsDefaultPage(array $service): bool
    {
        return in_array(ComposeService::of($service)->imageFamily(), self::FAMILIES, true)
            && ! self::hasProjectKey($service)
            && ! self::mountsContent($service['volumes'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function hasProjectKey(array $service): bool
    {
        foreach (self::PROJECT_KEYS as $key) {
            if (! empty($service[$key])) {
                return true;
            }
        }

        return false;
    }

    private static function mountsContent(mixed $volumes): bool
    {
        foreach (is_array($volumes) ? $volumes : [] as $volume) {
            if (self::isContentMount($volume)) {
                return true;
            }
        }

        return false;
    }

    /** A bind from the host, or anything mounted where content may sit; an unreadable entry counts too. */
    private static function isContentMount(mixed $volume): bool
    {
        [$source, $target, $type] = self::mountOf($volume);
        if ($target !== null && self::isUnder($target, self::SYSTEM_TARGETS)) {
            return false;
        }
        if ($target === null || $type === 'bind' || ($type === null && self::isHostPath($source))) {
            return true;
        }

        return ! self::isUnder($target, self::NON_CONTENT_TARGETS);
    }

    /**
     * A volume entry as [source, target, type]: short form `source:target[:mode]` or a bare anonymous target,
     * long form a map; target null when it cannot be read.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function mountOf(mixed $volume): array
    {
        if (is_array($volume)) {
            $type = is_string($volume['type'] ?? null) ? strtolower(trim($volume['type'])) : null;
            $target = is_string($volume['target'] ?? null) ? trim($volume['target']) : null;

            return [is_string($volume['source'] ?? null) ? trim($volume['source']) : '', $target, $type];
        }
        if (! is_string($volume) || trim($volume) === '') {
            return ['', null, null];
        }
        $fields = PortMapping::splitFields(trim($volume));

        return count($fields) === 1 ? ['', $fields[0], 'volume'] : [trim($fields[0]), trim($fields[1]), null];
    }

    private static function isHostPath(string $source): bool
    {
        return $source === '' || in_array($source[0], ['.', '/', '~', '$', '\\'], true);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function isUnder(string $target, array $prefixes): bool
    {
        $target = rtrim($target, '/');
        foreach ($prefixes as $prefix) {
            if ($target === $prefix || str_starts_with($target, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
