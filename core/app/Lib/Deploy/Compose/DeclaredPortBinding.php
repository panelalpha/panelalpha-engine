<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Project\PublicRouting\PublicPort;

/**
 * Publishes each `public_ports:` `to` on the account container, where the proxy forwards it: on the service that
 * offers that port, else the generated app service, else the first service that publishes a port at all.
 */
final class DeclaredPortBinding
{
    /**
     * @param  array<string, mixed>  $compose
     * @param  list<PublicPort>  $ports
     * @return array{compose: array<string, mixed>, published: list<string>, unbound: list<string>}
     */
    public static function apply(array $compose, array $ports): array
    {
        $published = [];
        $unbound = [];
        foreach ($ports as $port) {
            $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
            if (self::publishedByAny($services, $port)) {
                continue;
            }
            $service = self::serviceFor($services, $port);
            if ($service === null) {
                $unbound[] = "{$port->transport} {$port->to}";

                continue;
            }
            $compose['services'][$service]['ports'] = [...self::entries($services[$service]['ports'] ?? null), self::entry($port)];
            $published[] = "{$port->transport} {$port->to} on {$service}";
        }

        return ['compose' => $compose, 'published' => $published, 'unbound' => $unbound];
    }

    /** @param array<array-key, mixed> $services */
    private static function publishedByAny(array $services, PublicPort $port): bool
    {
        foreach ($services as $service) {
            foreach (is_array($service) ? self::entries($service['ports'] ?? null) : [] as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping !== null && $mapping->hostPort === $port->to && self::protocolOf($entry) === $port->transport) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $services */
    private static function serviceFor(array $services, PublicPort $port): ?string
    {
        foreach ($services as $name => $service) {
            if (is_array($service) && self::offers($service, $port->to)) {
                return (string) $name;
            }
        }
        if (is_array($services[GeneratedCompose::APP_SERVICE] ?? null)) {
            return GeneratedCompose::APP_SERVICE;
        }
        foreach ($services as $name => $service) {
            if (is_array($service) && self::entries($service['ports'] ?? null) !== []) {
                return (string) $name;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $service */
    private static function offers(array $service, int $port): bool
    {
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping !== null && ($mapping->containerPort ?? $mapping->hostPort) === $port) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function protocolOf(mixed $entry): string
    {
        if (is_array($entry)) {
            return strtolower((string) ($entry['protocol'] ?? PublicPort::TCP));
        }

        return is_string($entry) && str_ends_with(strtolower(trim($entry)), '/udp') ? PublicPort::UDP : PublicPort::TCP;
    }

    private static function entry(PublicPort $port): string
    {
        $mapping = "{$port->to}:{$port->to}";

        return $port->transport === PublicPort::UDP ? $mapping.'/udp' : $mapping;
    }

    /** @return list<mixed> */
    private static function entries(mixed $declared): array
    {
        if ($declared === null || $declared === '' || $declared === []) {
            return [];
        }

        return is_array($declared) ? array_values($declared) : [$declared];
    }
}
