<?php

namespace App\Lib\Domains;

use App\Models\Domain;

/**
 * The ports a domain answers HTTPS on, and the main one its project's public URL names. A domain that never set
 * them answers on 443 alone.
 */
final class HttpsPorts
{
    public const DEFAULT = 443;

    public const PORTS_KEY = 'https_ports';

    public const MAIN_KEY = 'https_port';

    /** @return list<int> */
    public static function of(Domain $domain): array
    {
        $stored = $domain->getDetails()[self::PORTS_KEY] ?? null;
        $ports = is_array($stored) ? array_values(array_unique(array_filter($stored, self::isPort(...)))) : [];

        return $ports === [] ? [self::DEFAULT] : $ports;
    }

    public static function main(Domain $domain): int
    {
        $ports = self::of($domain);
        $main = $domain->getDetails()[self::MAIN_KEY] ?? null;

        return is_int($main) && in_array($main, $ports, true) ? $main : $ports[0];
    }

    /** Whether the domain answers HTTPS anywhere but 443 alone, which deleting it has to close. */
    public static function hasExtra(Domain $domain): bool
    {
        return self::of($domain) !== [self::DEFAULT];
    }

    /** `:<port>` for a URL on the domain's main port, empty on 443. */
    public static function suffix(Domain $domain): string
    {
        $main = self::main($domain);

        return $main === self::DEFAULT ? '' : ':'.$main;
    }

    /**
     * Every port other than 443 a domain answers HTTPS on, with the names on it.
     *
     * @return array<int, list<string>>
     */
    public static function held(?int $exceptDomainId = null): array
    {
        $held = [];
        foreach (Domain::query()->where('details', 'like', '%"'.self::PORTS_KEY.'"%')->get() as $domain) {
            if ($exceptDomainId !== null && (int) $domain->id === $exceptDomainId) {
                continue;
            }
            foreach (self::of($domain) as $port) {
                if ($port !== self::DEFAULT) {
                    $held[$port][] = (string) $domain->domain;
                }
            }
        }
        ksort($held);

        return $held;
    }

    /** @return list<string> the names that answer HTTPS on $port, 443 aside */
    public static function namesOn(int $port, ?int $exceptDomainId = null): array
    {
        return self::held($exceptDomainId)[$port] ?? [];
    }

    private static function isPort(mixed $port): bool
    {
        return is_int($port) && $port >= 1 && $port <= 65535;
    }
}
