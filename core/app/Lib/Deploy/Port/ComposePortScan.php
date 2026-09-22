<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeYaml;

/**
 * The public ports a compose file offers, best first. Both ends of every
 * binding are inspected: a mapping whose container port is well known (5432,
 * 3306) is dropped even when the host port is something else (5434).
 */
final class ComposePortScan
{
    /** Ports a web application is most likely to want, in order. */
    private const PREFERRED = [80, 443, 8000, 8001, 8080, 8090, 8443, 3000, 5000, 9000];

    private const DEFAULT_PRIMARY = 8080;

    /**
     * @return array{all: list<int>, primary?: int}
     */
    public static function of(string $composePath): array
    {
        return self::fromServices(self::services($composePath));
    }

    /**
     * As {@see of()}, from an already-parsed compose array.
     *
     * @param array<string, mixed> $compose
     * @return array{all: list<int>, primary?: int}
     */
    public static function ofParsed(array $compose): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];

        return self::fromServices(array_values(array_filter($services, 'is_array')));
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return array{all: list<int>, primary?: int}
     */
    private static function fromServices(array $services): array
    {
        $ports = self::sorted(self::publicPorts($services));

        return $ports === [] ? ['all' => []] : ['all' => $ports, 'primary' => $ports[0]];
    }

    public static function primaryOf(string $composePath): int
    {
        return self::of($composePath)['primary'] ?? self::DEFAULT_PRIMARY;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function services(string $composePath): array
    {
        if (!file_exists($composePath)) {
            return [];
        }

        // Through ComposeYaml, not Symfony directly: it carries the anchor
        // rescue, without which Baserow's published 80 and 443 are invisible.
        $parsed = ComposeYaml::parseFile($composePath);
        $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        return array_values(array_filter($services, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return list<int>
     */
    private static function publicPorts(array $services): array
    {
        $ports = [];
        foreach ($services as $service) {
            foreach (self::servicePorts($service) as $port) {
                $ports[$port] = true;
            }
        }

        return array_keys($ports);
    }

    /**
     * `expose:` is lower priority than an explicit publish, so it is read
     * second and only adds ports the mappings did not already give.
     *
     * @param array<string, mixed> $service
     * @return list<int>
     */
    private static function servicePorts(array $service): array
    {
        $ports = [];
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping !== null && !InternalPorts::coversBinding($mapping, $service)) {
                    $ports[$mapping->hostPort] = true;
                }
            }
        }

        return array_keys($ports);
    }

    /**
     * @param mixed $declared
     * @return list<mixed>
     */
    private static function entries($declared): array
    {
        if ($declared === null || $declared === '' || $declared === []) {
            return [];
        }

        return is_array($declared) ? array_values($declared) : [$declared];
    }

    /**
     * Preferred ports first, in listed order; everything else numerically
     * after them.
     *
     * @param list<int> $ports
     * @return list<int>
     */
    private static function sorted(array $ports): array
    {
        usort($ports, static function (int $a, int $b): int {
            $rankA = self::rankOf($a);
            $rankB = self::rankOf($b);

            return $rankA === $rankB ? $a <=> $b : $rankA <=> $rankB;
        });

        return $ports;
    }

    private static function rankOf(int $port): int
    {
        $index = array_search($port, self::PREFERRED, true);

        return $index === false ? PHP_INT_MAX : (int) $index;
    }
}
