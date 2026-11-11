<?php

namespace App\Lib\Project\PublicRouting;

use App\Lib\Deploy\Platform\ManifestException;
use App\System\Services\Webserver\ProxyListenPort;

/**
 * `domain:` and `public_ports:` of an app config. What the file can get wrong fails here; what depends on
 * the server (a port someone else holds, no name to take) is decided at deploy.
 */
final class PublicRoutingReader
{
    public const DOMAIN_KEY = 'domain';

    public const PORTS_KEY = 'public_ports';

    public const KEYS = [self::DOMAIN_KEY, self::PORTS_KEY];

    private const ENTRY_KEYS = ['listen', 'to', 'transport'];

    /**
     * Null when the file has neither key.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws ManifestException
     */
    public static function read(array $raw, string $subject): ?PublicRouting
    {
        if (! array_key_exists(self::DOMAIN_KEY, $raw) && ! array_key_exists(self::PORTS_KEY, $raw)) {
            return null;
        }

        return new PublicRouting(self::domain($raw, $subject), self::ports($raw, $subject));
    }

    /** @param array<string, mixed> $raw */
    private static function domain(array $raw, string $subject): string
    {
        $domain = $raw[self::DOMAIN_KEY] ?? PublicRouting::DOMAIN_GENERATED;
        if (! in_array($domain, PublicRouting::DOMAINS, true)) {
            throw new ManifestException(
                $subject.": '".self::DOMAIN_KEY."' must be one of ".implode(', ', PublicRouting::DOMAINS)
            );
        }

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<PublicPort>
     */
    private static function ports(array $raw, string $subject): array
    {
        $declared = $raw[self::PORTS_KEY] ?? [];
        if (! is_array($declared) || ! array_is_list($declared)) {
            throw new ManifestException($subject.": '".self::PORTS_KEY."' must be a list of {listen, to, transport}");
        }

        $ports = [];
        foreach ($declared as $index => $entry) {
            $port = self::port($entry, $subject.': '.self::PORTS_KEY."[{$index}]");
            if (isset($ports[$port->key()])) {
                throw new ManifestException($subject.": '".self::PORTS_KEY."' lists {$port->transport} port {$port->listen} twice");
            }
            $ports[$port->key()] = $port;
        }

        return array_values($ports);
    }

    private static function port(mixed $entry, string $where): PublicPort
    {
        if (is_int($entry)) {
            $entry = ['listen' => $entry];
        }
        if (! is_array($entry) || array_is_list($entry) || array_diff(array_keys($entry), self::ENTRY_KEYS) !== []) {
            throw new ManifestException($where.' must be {listen: <port>, to: <port>, transport: tcp|udp}');
        }

        $listen = self::portNumber($entry['listen'] ?? null, $where.'.listen');
        $to = self::portNumber($entry['to'] ?? $listen, $where.'.to');
        $transport = $entry['transport'] ?? PublicPort::TCP;
        if (! in_array($transport, PublicPort::TRANSPORTS, true)) {
            throw new ManifestException($where.'.transport must be one of '.implode(', ', PublicPort::TRANSPORTS));
        }
        self::refuseEnginePort($listen, $where);

        return new PublicPort($listen, $to, $transport);
    }

    private static function portNumber(mixed $value, string $where): int
    {
        if (! is_int($value) || $value < 1 || $value > 65535) {
            throw new ManifestException($where.' must be a port number, 1-65535');
        }

        return $value;
    }

    private static function refuseEnginePort(int $listen, string $where): void
    {
        if (in_array($listen, ProxyListenPort::WEBSERVER, true)) {
            throw new ManifestException($where.".listen {$listen} is the webserver's own: HTTP reaches the app through its domain and `port`");
        }
        if (in_array($listen, ProxyListenPort::RESERVED, true)) {
            throw new ManifestException($where.".listen {$listen} is reserved by the engine");
        }
    }
}
