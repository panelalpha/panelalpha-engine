<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Sidecar\SidecarCredentials;

/**
 * The services a stack needs running: another service depends on them, or
 * reaches them by name as a host in its environment (`DB_HOST=db`,
 * `REDIS_URL=redis://cache:6379`).
 */
final class ServiceReferences
{
    /**
     * Words of an env key whose value is a host, `host:port` or a list of them
     * even without a scheme. Under any other key only a URL's host counts, so
     * `DB_CONNECTION=mysql` does not name a `mysql` service.
     *
     * @var list<string>
     */
    private const HOST_KEY_WORDS = ['HOST', 'HOSTS', 'HOSTNAME', 'SERVER', 'SERVERS', 'ADDR', 'ADDRESS', 'ENDPOINT', 'URL', 'URI'];

    /**
     * @param array<array-key, mixed> $services
     * @return list<string> the needed services' names as the file writes them
     */
    public static function needed(array $services): array
    {
        $references = [];
        foreach ($services as $name => $service) {
            if (is_array($service)) {
                $references[(string) $name] = array_flip(array_map('strtolower', [
                    ...ServiceDependencies::namesIn($service),
                    ...self::hostsIn($service['environment'] ?? null),
                ]));
            }
        }

        $needed = [];
        foreach ($services as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $names = array_flip(self::namesOf((string) $name, $service));
            foreach ($references as $other => $named) {
                if ($other !== (string) $name && array_intersect_key($named, $names) !== []) {
                    $needed[] = (string) $name;
                    break;
                }
            }
        }

        return $needed;
    }

    /**
     * The hosts an environment names: every URL's host, and the bare value of a
     * host-like key ({@see HOST_KEY_WORDS}). Whole hostnames, lowercased.
     *
     * @return list<string>
     */
    public static function hostsIn(mixed $environment): array
    {
        $hosts = [];
        foreach (SidecarCredentials::environmentMap($environment) as $key => $value) {
            $value = EnvVarDefault::resolve($value);
            if (preg_match_all('#[a-z][a-z0-9+.-]*://([^/?\#\s]*)#i', $value, $urls) > 0) {
                foreach ($urls[1] as $authority) {
                    $hosts = [...$hosts, ...self::hostsOf($authority)];
                }
            } elseif (self::isHostKey((string) $key)) {
                $hosts = [...$hosts, ...self::hostsOf($value)];
            }
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    /**
     * The names other containers reach a service by: its own, its
     * `container_name` and its network aliases.
     *
     * @param array<string, mixed> $service
     * @return list<string>
     */
    private static function namesOf(string $name, array $service): array
    {
        $names = [$name];
        if (is_string($service['container_name'] ?? null)) {
            $names[] = $service['container_name'];
        }
        foreach (is_array($service['networks'] ?? null) ? $service['networks'] : [] as $network) {
            foreach (is_array($network) && is_array($network['aliases'] ?? null) ? $network['aliases'] : [] as $alias) {
                if (is_string($alias)) {
                    $names[] = $alias;
                }
            }
        }

        return array_map('strtolower', array_values(array_filter($names, static fn (string $n): bool => $n !== '')));
    }

    private static function isHostKey(string $key): bool
    {
        $key = strtoupper($key);
        foreach (self::HOST_KEY_WORDS as $word) {
            // DB_HOST, and PGHOST written as one word.
            if (str_ends_with($key, $word) || in_array($word, preg_split('/[^A-Z0-9]+/', $key) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `db`, `db:5432`, `user:pass@db`, `a:27017,b:27017`: the hosts only. A
     * token that does not start with a hostname (an IPv6 literal, a path) is skipped.
     *
     * @return list<string>
     */
    private static function hostsOf(string $list): array
    {
        $hosts = [];
        foreach (preg_split('/[\s,;]+/', trim($list)) ?: [] as $token) {
            $at = strrpos($token, '@');
            $token = $at === false ? $token : substr($token, $at + 1);
            if (preg_match('#^([a-z0-9][a-z0-9._-]*)(?:[:/]|$)#i', $token, $host) === 1) {
                $hosts[] = $host[1];
            }
        }

        return $hosts;
    }
}
