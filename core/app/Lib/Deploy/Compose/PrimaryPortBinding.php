<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\PortMapping;

/**
 * Publishes the detected primary port on a service that only `expose:`s it, which binds nothing the DinD
 * proxy can reach; left alone when a service already publishes it.
 */
final class PrimaryPortBinding
{
    /**
     * @param  array<string, mixed>  $compose
     * @return array{compose: array<string, mixed>, published: ?int}
     */
    public static function apply(array $compose): array
    {
        $primary = ComposePortScan::ofParsed($compose)['primary'] ?? null;
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($primary === null || $services === []) {
            return ['compose' => $compose, 'published' => null];
        }

        if (self::isPublishedByAny($services, $primary)) {
            return ['compose' => $compose, 'published' => null];
        }

        foreach ($services as $name => $service) {
            if (! is_array($service) || ! self::offers($service, $primary)) {
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
     * Already bound to the account container's netns by some service: the proxy reaches the app.
     *
     * @param  array<array-key, mixed>  $services
     */
    private static function isPublishedByAny(array $services, int $primary): bool
    {
        foreach ($services as $service) {
            if (is_array($service) && self::publishesToHost($service, $primary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `ports:` entry that publishes $port on the host (account container).
     *
     * @param  array<string, mixed>  $service
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
     * The services that offer the detected primary port, which is where the account's own environment belongs.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function appServices(array $compose): array
    {
        $primary = ComposePortScan::ofParsed($compose)['primary'] ?? null;
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($primary === null) {
            return [];
        }

        $owners = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && self::offers($service, $primary)) {
                $owners[] = (string) $name;
            }
        }

        return $owners;
    }

    /**
     * The service offers $port through `expose:` or a `ports:` container port.
     *
     * @param  array<string, mixed>  $service
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
     * @param  mixed  $declared
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
