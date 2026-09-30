<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Sidecar\EnvSidecars;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use Symfony\Component\Yaml\Yaml;

/**
 * Backing services the application needs: the datastores a compose file
 * declares, plus the ones only a DATABASE_URL or REDIS_URL admits to.
 *
 * The second half is the one that catches people out. A framework template
 * ships no compose at all and points DATABASE_URL at localhost; deployed as
 * written it starts cleanly and 500s on the first request.
 */
final class ServicesReport
{
    private readonly string $projectDir;

    /**
     * @param ?string $composePath the compose file detection chose, which may
     *        be a recipe's app-config compose rather than one the repository ships
     */
    public function __construct(string $projectDir, private readonly ?string $composePath = null)
    {
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function of(string $projectDir, ?string $composePath = null): array
    {
        return (new self($projectDir, $composePath))->build();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(): array
    {
        return array_values($this->declared() + $this->impliedByEnvironment());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function declared(): array
    {
        $services = [];
        foreach ($this->composeServices() as $name => $service) {
            $isStore = SidecarEngine::isKnownDatastore($name, $service);
            $services[$name] = [
                'name' => $name,
                'role' => $isStore ? 'datastore' : 'application',
                'engine' => $isStore ? SidecarEngine::resolve($name, $service) : null,
                'image' => isset($service['image']) ? (string) $service['image'] : null,
                'ports' => array_values(array_map('intval', SidecarEngine::allPorts($service))),
                'origin' => 'compose',
            ];
        }

        return $services;
    }

    /**
     * Not in any file the project ships: inferred from a URL in its .env, and
     * the engine would add it at deploy time.
     *
     * @return array<string, array<string, mixed>>
     */
    private function impliedByEnvironment(): array
    {
        $services = [];
        foreach (EnvSidecars::fromProjectDir($this->projectDir)['services'] as $name => $service) {
            $service = is_array($service) ? $service : [];
            $engine = SidecarEngine::resolve((string) $name, $service);
            $services[(string) $name] = [
                'name' => (string) $name,
                'role' => 'datastore',
                'engine' => $engine,
                'image' => isset($service['image']) ? (string) $service['image'] : null,
                'ports' => $engine === null ? [] : array_values(array_filter([SidecarEngine::portFor($engine)])),
                'origin' => 'env',
            ];
        }

        return $services;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function composeServices(): array
    {
        $path = $this->composePath !== null && is_file($this->composePath)
            ? $this->composePath
            : ComposeFileInspector::firstIn($this->projectDir);
        if ($path === null) {
            return [];
        }

        // Through ComposeYaml, which reads the anchor shapes Docker accepts
        // and Symfony does not. This had its own catch returning [], so a
        // compose file the parser choked on came back as "no services" rather
        // than as a problem -- which is how source_inspect reported Lychee as
        // `deployable: true, issue: null, services: []` for a file declaring
        // seven services, while the deploy path could read it perfectly well.
        $parsed = ComposeYaml::parseFile($path);
        if ($parsed === null || !is_array($parsed['services'] ?? null)) {
            return [];
        }

        $services = [];
        foreach ($parsed['services'] as $name => $service) {
            if (is_array($service)) {
                $services[(string) $name] = $service;
            }
        }

        return $services;
    }
}
