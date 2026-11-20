<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;

/**
 * Rejects source URLs the migration worker must not fetch: non-HTTP schemes, and hosts with any address
 * {@see SourceIpPolicy} refuses. Every refusal gets the same answer, so it says nothing about what listens there.
 */
final class SourceFetchGuard
{
    public const REFUSED = 'Source WordPress URL must lead to a public address: loopback, link-local, private, '
        ."reserved, Docker network and this Engine's own addresses are refused.";

    /** Redirects followed as a plain GET without the body and the headers; every other one resends the request. */
    public const REDIRECTS_AS_GET = [301, 302, 303];

    /**
     * Check the URL and every address its host resolves to.
     *
     * @return list<string> the checked addresses, the first of which a request should connect to
     */
    public static function assertAllowed(string $url, SourceIpPolicy $policy, ?callable $resolve = null): array
    {
        $host = self::assertShape($url);
        $addresses = self::addressesFor($host, $resolve);
        foreach ($addresses as $ip) {
            self::assertAddressAllowed($ip, $policy, $host);
        }

        return $addresses;
    }

    /**
     * Scheme and host only, for a URL whose address comes from elsewhere (`source_ip`).
     *
     * @return string the host, without IPv6 brackets
     */
    public static function assertShape(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new RuntimeException('Source WordPress URL is invalid');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('Source WordPress URL must use http or https');
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Source WordPress URL is missing a host');
        }

        return trim($host, '[]');
    }

    public static function assertAddressAllowed(string $ip, SourceIpPolicy $policy, ?string $host = null): void
    {
        if ($policy->refuses($ip, $host)) {
            throw new RuntimeException(self::REFUSED);
        }
    }

    /**
     * Turn a redirect Location into an absolute URL. The caller checks the result.
     */
    public static function resolveRedirect(string $current, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new RuntimeException('Source WordPress redirect is missing a target');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $base = self::redirectBase($current);
        $origin = self::origin($base);

        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.self::directory($base).$location;
    }

    /**
     * No hop leaves https for plain http. A hop that resends the request (a 307 or 308) carries the migrator
     * token and the wp-admin password, so it stays on the origin or its `www.` twin, or moves them to https.
     */
    public static function assertRedirectAllowed(int $status, string $from, string $to): void
    {
        $source = self::redirectBase($from);
        $target = self::redirectBase($to);
        if (self::scheme($source) === 'https' && self::scheme($target) === 'http') {
            throw SourceCheckFailed::unreachable('The source WordPress site redirects from https to plain http at '
                .self::origin($target).'; enter that URL to import over plain http');
        }
        if (! in_array($status, self::REDIRECTS_AS_GET, true) && ! self::staysOnSite($source, $target)) {
            throw SourceCheckFailed::unreachable('The source WordPress site redirects to '
                .self::origin($target).'; enter that URL as the source URL');
        }
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $from
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $to
     */
    private static function staysOnSite(array $from, array $to): bool
    {
        if (! SourceIpPin::sameSite($from['host'], $to['host'])) {
            return false;
        }
        if (self::scheme($from) === self::scheme($to)) {
            return self::port($from) === self::port($to);
        }

        return self::upgradesToHttps($from, $to);
    }

    /**
     * http to https on the same port, or from the default 80 to the default 443.
     *
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $from
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $to
     */
    private static function upgradesToHttps(array $from, array $to): bool
    {
        $ports = [self::port($from), self::port($to)];

        return self::scheme($from) === 'http' && self::scheme($to) === 'https'
            && ($ports[0] === $ports[1] || $ports === [80, 443]);
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $base
     */
    private static function scheme(array $base): string
    {
        return strtolower($base['scheme']);
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $base
     */
    private static function port(array $base): int
    {
        return $base['port'] ?? (self::scheme($base) === 'https' ? 443 : 80);
    }

    /**
     * @return array{scheme: string, host: string, port?: int, path?: string}
     */
    private static function redirectBase(string $current): array
    {
        $base = parse_url($current);
        if (! is_array($base) || ! isset($base['scheme'], $base['host']) || ! is_string($base['host'])) {
            throw new RuntimeException('Source WordPress URL is invalid');
        }

        return $base;
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $base
     */
    private static function origin(array $base): string
    {
        $host = $base['host'];
        if (str_contains($host, ':')) {
            $host = '['.trim($host, '[]').']';
        }
        $origin = $base['scheme'].'://'.$host;
        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }

        return $origin;
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string}  $base
     */
    private static function directory(array $base): string
    {
        $path = isset($base['path']) && is_string($base['path']) ? $base['path'] : '/';
        $slash = strrpos($path, '/');

        return $slash === false ? '/' : substr($path, 0, $slash + 1);
    }

    /**
     * @param  null|callable(string): mixed  $dnsRecords  the host's A and AAAA records; dns_get_record() by default
     * @param  null|callable(string): mixed  $hostByName  the host's IPv4 addresses; gethostbynamel() by default
     * @return list<string>
     */
    public static function resolveHost(string $host, ?callable $dnsRecords = null, ?callable $hostByName = null): array
    {
        $ips = [];
        if ($dnsRecords !== null || function_exists('dns_get_record')) {
            $ips = self::dnsRecordAddresses($host, $dnsRecords ?? static fn (string $name): mixed => @dns_get_record($name, DNS_A + DNS_AAAA));
        }
        if ($ips === [] && ($hostByName !== null || function_exists('gethostbynamel'))) {
            $ips = self::hostByNameAddresses($host, $hostByName ?? static fn (string $name): mixed => @gethostbynamel($name));
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param  callable(string): mixed  $dnsRecords
     * @return list<string>
     */
    private static function dnsRecordAddresses(string $host, callable $dnsRecords): array
    {
        $ips = [];
        $records = $dnsRecords($host);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * @param  callable(string): mixed  $hostByName
     * @return list<string>
     */
    private static function hostByNameAddresses(string $host, callable $hostByName): array
    {
        $ips = [];
        $v4 = $hostByName($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /**
     * @return list<string>
     */
    private static function addressesFor(string $host, ?callable $resolve): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $resolver = $resolve ?? self::resolveHost(...);
        $ips = $resolver($host);
        if (! is_array($ips) || $ips === []) {
            throw new RuntimeException('Source WordPress host did not resolve');
        }

        $addresses = [];
        foreach ($ips as $ip) {
            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Source WordPress host did not resolve');
        }

        return $addresses;
    }
}
