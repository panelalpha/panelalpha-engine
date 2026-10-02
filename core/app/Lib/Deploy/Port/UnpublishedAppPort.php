<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Sidecar\ServiceRole;

/**
 * The port an application listens on when its compose file publishes none.
 *
 * Detection then fell back to 8080 while reiverr listens on 9494, and the
 * domain 502'd. Before that guess, the service is asked in turn: a `PORT`-style
 * variable in its environment, the ports its image `EXPOSE`s, and a port the
 * repository's other compose files publish for a service of the same name
 * (reiverr's docker-compose.prod.yml publishes 9494). The port is added as
 * `expose:`, which {@see \App\Lib\Deploy\Compose\PrimaryPortBinding} publishes.
 */
final class UnpublishedAppPort
{
    /** Variables an application reads its listen port from. */
    private const PORT_VARIABLES = ['PORT', 'HTTP_PORT', 'APP_PORT', 'SERVER_PORT', 'WEB_PORT', 'LISTEN_PORT'];

    /** A compose file named for a workstation describes a dev server's port, not the app's. */
    private const NON_PRODUCTION_WORDS = ['dev', 'development', 'local', 'test', 'tests', 'testing', 'ci', 'e2e', 'debug', 'override'];

    /**
     * @param array<string, mixed> $compose the run file being written
     * @param callable(string): list<int>|null $imagePorts the ports an image declares
     * @param array<string, string> $otherFiles the repository's other compose files, filename => YAML
     * @return array{compose: array<string, mixed>, service: string, port: int, source: string}|null
     *         null when a port is already published or nothing names one
     */
    public static function apply(array $compose, ?callable $imagePorts = null, array $otherFiles = []): ?array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($services === [] || isset(ComposePortScan::ofParsed($compose)['primary'])) {
            return null;
        }

        $published = self::publishedElsewhere($otherFiles);
        foreach ($services as $name => $service) {
            if (!is_array($service) || !self::couldBeTheApp((string) $name, $service)) {
                continue;
            }
            $found = self::fromEnvironment($service['environment'] ?? null)
                ?? self::fromImage($service, $imagePorts)
                ?? $published[strtolower((string) $name)]
                ?? null;
            if ($found === null) {
                continue;
            }
            [$port, $source] = $found;
            $expose = is_array($service['expose'] ?? null) ? array_values($service['expose']) : [];
            $expose[] = (string) $port;
            $compose['services'][$name]['expose'] = $expose;

            return ['compose' => $compose, 'service' => (string) $name, 'port' => $port, 'source' => $source];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function couldBeTheApp(string $name, array $service): bool
    {
        $profiles = $service['profiles'] ?? null;
        if ((is_array($profiles) && $profiles !== []) || (is_string($profiles) && trim($profiles) !== '')) {
            return false;
        }
        if (is_string($service['network_mode'] ?? null)) {
            return false;
        }

        return !ServiceRole::isKnownDatastore($name, $service) && !ServiceRole::isJobService($name, $service);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private static function fromEnvironment(mixed $environment): ?array
    {
        if (!is_array($environment)) {
            return null;
        }
        $values = [];
        foreach ($environment as $key => $value) {
            if (is_int($key) && is_string($value) && str_contains($value, '=')) {
                [$key, $value] = explode('=', $value, 2);
            }
            if (is_string($key) && is_scalar($value)) {
                $values[strtoupper(trim($key))] = trim((string) $value, " \"'");
            }
        }
        foreach (self::PORT_VARIABLES as $variable) {
            $port = self::webPort($values[$variable] ?? '');
            if ($port !== null) {
                return [$port, "its {$variable} variable"];
            }
        }

        return null;
    }

    /**
     * An image built here has no registry entry to ask.
     *
     * @param array<string, mixed> $service
     * @param callable(string): list<int>|null $imagePorts
     * @return array{0: int, 1: string}|null
     */
    private static function fromImage(array $service, ?callable $imagePorts): ?array
    {
        $image = is_string($service['image'] ?? null) ? trim($service['image']) : '';
        if ($imagePorts === null || $image === '' || isset($service['build']) || str_contains($image, '$')) {
            return null;
        }
        foreach ($imagePorts($image) as $declared) {
            $port = self::webPort((string) $declared);
            if ($port !== null) {
                return [$port, "the image's EXPOSE"];
            }
        }

        return null;
    }

    /**
     * Container ports other compose files publish, by lowercased service name.
     *
     * @param array<string, string> $files
     * @return array<string, array{0: int, 1: string}>
     */
    private static function publishedElsewhere(array $files): array
    {
        $found = [];
        foreach ($files as $filename => $raw) {
            $middle = preg_replace('/^(docker-)?compose\.|\.ya?ml$/i', '', strtolower((string) $filename));
            $words = preg_split('/[._-]+/', (string) $middle) ?: [];
            if (array_intersect($words, self::NON_PRODUCTION_WORDS) !== []) {
                continue;
            }
            $parsed = ComposeYaml::parse($raw);
            $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
            foreach ($services as $name => $service) {
                $key = strtolower((string) $name);
                if (isset($found[$key]) || !is_array($service) || !is_array($service['ports'] ?? null)) {
                    continue;
                }
                foreach ($service['ports'] as $entry) {
                    $mapping = PortMapping::parse($entry);
                    $port = $mapping === null ? null : self::webPort((string) ($mapping->containerPort ?? $mapping->hostPort));
                    if ($port !== null) {
                        $found[$key] = [$port, "the port {$filename} publishes for it"];
                        break;
                    }
                }
            }
        }

        return $found;
    }

    private static function webPort(string $value): ?int
    {
        $value = trim(explode('/', $value)[0]);
        if (!ctype_digit($value)) {
            return null;
        }

        return InternalPorts::isWebCandidate((int) $value) ? (int) $value : null;
    }
}
