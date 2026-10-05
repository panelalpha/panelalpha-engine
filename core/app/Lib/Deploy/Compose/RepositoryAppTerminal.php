<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\ServiceRole;
use Symfony\Component\Yaml\Yaml;

/**
 * The `tty` / `stdin_open` a repository's own compose gives the service that
 * runs its Dockerfile. An image whose CMD ends in `exec bash` (MintHCM) relies
 * on them: without a terminal bash reads EOF, exits 0 and the container
 * restart-loops. Both settings are harmless, so they are carried over as set.
 */
final class RepositoryAppTerminal
{
    private const KEYS = ['tty', 'stdin_open'];

    /**
     * @param array<string, ?string> $composeFiles path relative to the project
     *        root => its contents (null when absent), in the order to consult
     * @return array<string, true> the keys of {@see KEYS} the app service sets
     */
    public static function settings(string $dockerfile, array $composeFiles, ?string $projectIdentity = null): array
    {
        $dockerfile = self::normalise($dockerfile);
        foreach ($composeFiles as $path => $raw) {
            $services = self::services($raw);
            if ($services === []) {
                continue;
            }
            $service = self::buildingService($services, dirname((string) $path), $dockerfile)
                ?? self::applicationService($services, $projectIdentity);
            if ($service !== null) {
                return self::terminalOf($service);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function services(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
    }

    /**
     * The service whose `build:` resolves to $dockerfile.
     *
     * @param array<string, mixed> $services
     * @return array<string, mixed>|null
     */
    private static function buildingService(array $services, string $composeDir, string $dockerfile): ?array
    {
        foreach ($services as $service) {
            if (!is_array($service) || !isset($service['build'])) {
                continue;
            }
            $build = $service['build'];
            $context = is_array($build) ? ($build['context'] ?? '.') : $build;
            $file = is_array($build) ? ($build['dockerfile'] ?? 'Dockerfile') : 'Dockerfile';
            if (!is_string($context) || !is_string($file)) {
                continue;
            }
            if (self::normalise($composeDir . '/' . $context . '/' . $file) === $dockerfile) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The one service the file runs as its application (MintHCM's
     * `minthcm-web` runs the published image of the Dockerfile we build).
     *
     * @param array<string, mixed> $services
     * @return array<string, mixed>|null
     */
    private static function applicationService(array $services, ?string $projectIdentity): ?array
    {
        $found = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && ServiceRole::isApplication((string) $name, $service, $services, $projectIdentity)) {
                $found[] = $service;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, true>
     */
    private static function terminalOf(array $service): array
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $value = $service[$key] ?? null;
            if ($value === true || (is_string($value) && strtolower(trim($value)) === 'true')) {
                $settings[$key] = true;
            }
        }

        return $settings;
    }

    /** `./docker/../docker/Dockerfile` and `docker/Dockerfile` compare equal. */
    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
