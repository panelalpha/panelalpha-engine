<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeYaml;

/**
 * The public ports a compose file offers, best first. Both ends of every
 * binding are inspected: a mapping whose container port is well known (5432,
 * 3306) is dropped even when the host port is something else (5434). What was
 * dropped comes back too, under `refused`, so a caller can tell a compose file
 * that offers nothing from one that offers only a database.
 *
 * @psalm-type Refusal = array{port: int, reason: string, service: string}
 * @psalm-type Scan = array{all: list<int>, primary?: int, refused: list<Refusal>}
 */
final class ComposePortScan
{
    /** Ports a web application is most likely to want, in order. */
    private const PREFERRED = [80, 443, 8000, 8001, 8080, 8090, 8443, 3000, 5000, 9000];

    private const DEFAULT_PRIMARY = 8080;

    /**
     * @return Scan
     */
    public static function of(string $composePath): array
    {
        return self::fromServices(self::services($composePath));
    }

    /**
     * As {@see of()}, from an already-parsed compose array.
     *
     * @param array<string, mixed> $compose
     * @return Scan
     */
    public static function ofParsed(array $compose): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];

        return self::fromServices(array_values(array_filter($services, 'is_array')));
    }

    /**
     * @param list<array<string, mixed>> $services
     * @return Scan
     */
    private static function fromServices(array $services): array
    {
        $scan = self::scan($services);
        $ports = self::sorted($scan['public']);
        // A port another service publishes legitimately is not refused, however
        // the datastore next to it declared it.
        $refused = array_values(array_filter(
            $scan['refused'],
            static fn (array $entry): bool => !in_array($entry['port'], $ports, true)
        ));

        return $ports === []
            ? ['all' => [], 'refused' => $refused]
            : ['all' => $ports, 'primary' => $ports[0], 'refused' => $refused];
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
     * @return array{public: list<int>, refused: list<Refusal>}
     */
    private static function scan(array $services): array
    {
        $ports = [];
        $refused = [];
        foreach ($services as $service) {
            $found = self::serviceScan($service);
            foreach ($found['public'] as $port) {
                $ports[$port] = true;
            }
            foreach ($found['refused'] as $entry) {
                $refused[$entry['port']] ??= $entry;
            }
        }

        return ['public' => array_keys($ports), 'refused' => array_values($refused)];
    }

    /**
     * `expose:` is lower priority than an explicit publish, so it is read
     * second and only adds ports the mappings did not already give.
     *
     * A filtered binding is reported rather than dropped: "the compose file
     * offers no port" and "it offers MySQL's, which we will never proxy" are
     * different answers, and only one of them means the app is misconfigured.
     *
     * @param array<string, mixed> $service
     * @return array{public: list<int>, refused: list<Refusal>}
     */
    private static function serviceScan(array $service): array
    {
        $ports = [];
        $refused = [];
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping === null) {
                    continue;
                }
                if (!InternalPorts::coversBinding($mapping, $service)) {
                    $ports[$mapping->hostPort] = true;
                    continue;
                }
                $refused[$mapping->hostPort] ??= self::refusal($mapping, $service);
            }
        }

        return ['public' => array_keys($ports), 'refused' => array_values($refused)];
    }

    /**
     * Why {@see InternalPorts::coversBinding()} turned a binding down: the port
     * itself is a datastore's, or the image behind it is.
     *
     * @param array<string, mixed> $service
     * @return Refusal
     */
    private static function refusal(PortMapping $mapping, array $service): array
    {
        $label = InternalPorts::datastoreOn($mapping->hostPort)
            ?? ($mapping->containerPort === null ? null : InternalPorts::datastoreOn($mapping->containerPort));

        if ($label !== null) {
            return ['port' => $mapping->hostPort, 'reason' => 'datastore', 'service' => $label];
        }

        $image = is_string($service['image'] ?? null) ? $service['image'] : 'datastore image';

        return ['port' => $mapping->hostPort, 'reason' => 'datastore_image', 'service' => $image];
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
