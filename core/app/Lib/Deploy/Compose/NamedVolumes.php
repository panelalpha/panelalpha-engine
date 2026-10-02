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
     * A mount whose whole source is a bare `${VAR}` nothing sets interpolates to
     * `:/config`, and compose rejects the file (deemix: "Set DEEMIX_CONFIG_PATH in
     * the shell"). Each becomes a named volume called after the variable.
     *
     * @param array<string, mixed> $compose
     * @param array<string, list<?string>|string> $env what compose may interpolate with
     * @return array{compose: array<string, mixed>, replaced: list<string>}
     */
    public static function forUnsetSources(array $compose, array $env): array
    {
        $replaced = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            if (!is_array($service) || !is_array($service['volumes'] ?? null)) {
                continue;
            }
            foreach ($service['volumes'] as $i => $volume) {
                if (!is_string($volume) || preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}(:.+)$/', trim($volume), $m) !== 1) {
                    continue;
                }
                $values = $env[$m[1]] ?? [null];
                if (array_filter(is_array($values) ? $values : [$values], static fn ($v): bool => $v !== null && $v !== '') !== []) {
                    continue;
                }
                $volumeName = strtolower(str_replace('_', '-', $m[1]));
                $compose['services'][$name]['volumes'][$i] = $volumeName . $m[2];
                if (!is_array($compose['volumes'] ?? null)) {
                    $compose['volumes'] = [];
                }
                $compose['volumes'][$volumeName] ??= null;
                $replaced[] = "{$name}: {$m[1]} -> {$volumeName}";
            }
        }

        return ['compose' => $compose, 'replaced' => $replaced];
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
