<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\PortMapping;

/**
 * Publishes the detected primary port on a service that only `expose:`s it.
 *
 * The DinD proxy routes the domain to the account container on the detected
 * primary port, but an `expose:`-only service binds nothing to that netns, so
 * the domain 502s. Adding `ports: "<primary>:<primary>"` makes the account
 * container bind the port the proxy already targets. Left alone when a service
 * already publishes the port, so nothing is double-published.
 */
final class PrimaryPortBinding
{
    /**
     * @param array<string, mixed> $compose
     * @return array{compose: array<string, mixed>, published: ?int}
     */
    public static function apply(array $compose): array
    {
        $primary = ComposePortScan::ofParsed($compose)['primary'] ?? null;
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($primary === null || $services === []) {
            return ['compose' => $compose, 'published' => null];
        }

        // Already bound to the account container's netns by some service: the
        // proxy reaches the app, nothing to add.
        foreach ($services as $service) {
            if (is_array($service) && self::publishesToHost($service, $primary)) {
                return ['compose' => $compose, 'published' => null];
            }
        }

        // Publish it on the service that only exposes it.
        foreach ($services as $name => $service) {
            if (!is_array($service) || !self::offers($service, $primary)) {
                continue;
            }
            $ports = isset($service['ports']) && is_array($service['ports']) ? array_values($service['ports']) : [];
            $ports[] = "{$primary}:{$primary}";
            $compose['services'][$name]['ports'] = $ports;

            return ['compose' => $compose, 'published' => $primary];
        }

        return ['compose' => $compose, 'published' => null];
    }

    /**
     * A `ports:` entry that publishes $port on the host (account container).
     *
     * @param array<string, mixed> $service
     */
    private static function publishesToHost(array $service, int $port): bool
    {
        foreach (self::entries($service['ports'] ?? null) as $entry) {
            $mapping = PortMapping::parse($entry);
            if ($mapping !== null && $mapping->hostPort === $port) {
                return true;
            }
        }

        return false;
    }

    /**
     * The service offers $port through `expose:` or a `ports:` container port.
     *
     * @param array<string, mixed> $service
     */
    private static function offers(array $service, int $port): bool
    {
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping === null) {
                    continue;
                }
                if ($mapping->hostPort === $port || ($mapping->containerPort ?? $mapping->hostPort) === $port) {
                    return true;
                }
            }
        }

        return false;
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
}
