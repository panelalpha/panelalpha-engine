<?php

namespace App\System\Project\Dind\Generation;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * The second copy of the routed service a redeploy starts beside the running
 * one: the same compose files plus an override, its own compose
 * project, ports left to Docker, the running project's networks and volumes.
 */
final class NextGeneration
{
    /** Appended to the running compose project's name for the second generation. */
    public const PROJECT_SUFFIX = '-next';

    /** Set on the second generation's containers, so a sweep can find them. */
    public const LABEL = 'panelalpha.generation';

    /** {@see GeneratedCompose::LABEL}'s value prefix on a Procfile process service. */
    private const PROCESS_LABEL_PREFIX = 'procfile-';

    private const GENERATED_LABEL = 'panelalpha.generated';

    /**
     * @param array<string, mixed> $config
     * @param array<int, int> $ports published port => container port
     */
    private function __construct(
        public readonly string $project,
        public readonly string $service,
        public readonly array $ports,
        private readonly array $config,
    ) {
    }

    public static function projectNameFor(string $project): string
    {
        return $project . self::PROJECT_SUFFIX;
    }

    public function projectName(): string
    {
        return self::projectNameFor($this->project);
    }

    /**
     * Or why two copies of the service publishing $routedPort cannot run side by side.
     *
     * @param array<string, mixed> $config `docker compose config --format json`
     */
    public static function plan(array $config, int $routedPort, bool $repositoryCompose): self|string
    {
        $project = is_string($config['name'] ?? null) ? trim($config['name']) : '';
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        if ($project === '' || $services === []) {
            return 'its compose file could not be read';
        }

        $name = null;
        foreach ($services as $candidate => $service) {
            if (is_array($service) && array_key_exists($routedPort, self::published($service))) {
                $name = (string) $candidate;
                break;
            }
        }
        if ($name === null) {
            return "no service publishes port {$routedPort}, the one the site is routed to";
        }

        $service = $services[$name];
        if (!empty($service['network_mode'])) {
            return "{$name} shares another network namespace (network_mode: {$service['network_mode']})";
        }
        if (!empty($service['container_name'])) {
            return "{$name} names its container ({$service['container_name']}), and a name exists only once";
        }
        foreach (is_array($service['ports'] ?? null) ? $service['ports'] : [] as $port) {
            if (is_array($port) && self::singleTcp($port) === null) {
                return "{$name} publishes a port range or a UDP port";
            }
        }
        if ($repositoryCompose && count($services) > 1) {
            return 'the repository\'s compose file runs ' . count($services)
                . ' services, and only a single-service stack is started twice';
        }

        return new self($project, $name, self::published($service), $config);
    }

    /**
     * Replaced when the new version takes over; sidecars keep running.
     *
     * @return list<string>
     */
    public function generationServices(string $projectDir): array
    {
        $root = rtrim($projectDir, '/');
        $names = [];
        foreach (is_array($this->config['services'] ?? null) ? $this->config['services'] : [] as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $label = (string) ($service['labels'][self::GENERATED_LABEL] ?? '');
            if ($name === $this->service || str_starts_with($label, self::PROCESS_LABEL_PREFIX) || self::bindsUnder($service, $root)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** Layered over the project's own compose files to start the second copy. */
    public function override(): string
    {
        $service = $this->config['services'][$this->service];
        $targets = array_values(array_unique(array_values($this->ports)));
        $override = [
            'ports' => new TaggedValue('override', array_map(
                static fn (int $target): array => ['target' => $target, 'protocol' => 'tcp'],
                $targets
            )),
            'labels' => [self::LABEL => 'next'],
        ];
        if (isset($service['build'])) {
            $override['build'] = new TaggedValue('reset', null);
            $override['image'] = is_string($service['image'] ?? null) && $service['image'] !== ''
                ? $service['image']
                : $this->project . '-' . $this->service;
        }

        $compose = ['services' => [$this->service => $override]];
        $networks = is_array($service['networks'] ?? null) && $service['networks'] !== []
            ? array_keys($service['networks'])
            : ['default'];
        foreach ($networks as $key) {
            $compose['networks'][$key] = new TaggedValue('override', [
                'name' => $this->resourceName('networks', (string) $key),
                'external' => true,
            ]);
        }
        foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
            $source = is_array($volume) && ($volume['type'] ?? null) === 'volume' ? ($volume['source'] ?? null) : null;
            if (is_string($source) && $source !== '') {
                $compose['volumes'][$source] = new TaggedValue('override', [
                    'name' => $this->resourceName('volumes', $source),
                    'external' => true,
                ]);
            }
        }

        return Yaml::dump($compose, 6, 2);
    }

    /** The name compose gave a network or volume of the running project. */
    private function resourceName(string $kind, string $key): string
    {
        $name = $this->config[$kind][$key]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->project . '_' . $key;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<int, int>
     */
    private static function published(array $service): array
    {
        $ports = [];
        foreach (is_array($service['ports'] ?? null) ? $service['ports'] : [] as $port) {
            $pair = is_array($port) ? self::singleTcp($port) : null;
            if ($pair !== null) {
                $ports[$pair[0]] = $pair[1];
            }
        }

        return $ports;
    }

    /**
     * @param array<string, mixed> $port one long-syntax entry from `compose config`
     * @return ?array{0: int, 1: int}
     */
    private static function singleTcp(array $port): ?array
    {
        $published = (string) ($port['published'] ?? '');
        $target = $port['target'] ?? null;
        if (strtolower((string) ($port['protocol'] ?? 'tcp')) !== 'tcp' || !ctype_digit($published) || !is_int($target)) {
            return null;
        }

        return [(int) $published, $target];
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function bindsUnder(array $service, string $root): bool
    {
        foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
            $source = is_array($volume) && ($volume['type'] ?? null) === 'bind' ? rtrim((string) ($volume['source'] ?? ''), '/') : '';
            if ($source !== '' && ($source === $root || str_starts_with($source, $root . '/'))) {
                return true;
            }
        }

        return false;
    }
}
