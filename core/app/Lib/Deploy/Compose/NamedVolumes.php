<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\EnvVarDefault;

/**
 * The `volumes:` block a reduced stack still needs.
 *
 * Only named volumes the kept services actually mount: a bind mount points at
 * a workstation path that does not exist in the account, and a declared
 * volume nothing uses is a directory the account pays for.
 */
final class NamedVolumes
{
    /**
     * @param array<string, array<string, mixed>> $services
     * @param array<string, mixed> $declared the file's own `volumes:` block
     * @return array<string, mixed>
     */
    public static function usedBy(array $services, array $declared): array
    {
        $used = [];
        foreach ($services as $service) {
            foreach (self::mountedNames($service) as $name) {
                $used[$name] = $declared[$name] ?? null;
            }
        }

        return $used;
    }

    /**
     * @param mixed $service
     * @return list<string>
     */
    private static function mountedNames($service): array
    {
        if (!is_array($service) || !is_array($service['volumes'] ?? null)) {
            return [];
        }

        $names = [];
        foreach ($service['volumes'] as $volume) {
            $source = trim(self::sourceOf($volume));
            if (self::isNamedVolume($source)) {
                $names[] = $source;
            }
        }

        return $names;
    }

    /**
     * @param mixed $volume
     */
    private static function sourceOf($volume): string
    {
        if (is_string($volume)) {
            // Resolved first: `${DOCKER_SOCKET:-/var/run/docker.sock}:/var/run/docker.sock`
            // split as written gives `${DOCKER_SOCKET`, which compose rejects as a volume name.
            $volume = EnvVarDefault::resolve($volume);

            return str_contains($volume, ':') ? explode(':', $volume, 2)[0] : '';
        }
        if (!is_array($volume) || strtolower((string) ($volume['type'] ?? 'volume')) === 'bind') {
            return '';
        }

        return EnvVarDefault::resolve((string) ($volume['source'] ?? ''));
    }

    /** Docker's own rule for a volume name; a path or a leftover `${...}` is not one. */
    private static function isNamedVolume(string $source): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $source) === 1;
    }
}
