<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;

/**
 * Rejects source URLs the migration worker must not fetch: non-HTTP schemes,
 * and a host with any address {@see SourceIpPolicy} refuses (loopback,
 * link-local, private, reserved, Docker networks, the Engine's own). Every
 * refused address gets the same answer, so a refusal says nothing about what
 * listens there.
 */
final class SourceFetchGuard
{
    public const REFUSED = 'Source WordPress URL must lead to a public address: loopback, link-local, private, '
        ."reserved, Docker network and this Engine's own addresses are refused.";

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

        $base = parse_url($current);
        if (! is_array($base) || ! isset($base['scheme'], $base['host']) || ! is_string($base['host'])) {
            throw new RuntimeException('Source WordPress URL is invalid');
        }

        $host = $base['host'];
        if (str_contains($host, ':')) {
            $host = '['.trim($host, '[]').']';
        }
        $origin = $base['scheme'].'://'.$host;
        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = isset($base['path']) && is_string($base['path']) ? $base['path'] : '/';
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);

        return $origin.$dir.$location;
    }

    /**
     * @return list<string>
     */
    public static function resolveHost(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
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
        }
        if ($ips === [] && function_exists('gethostbynamel')) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                foreach ($v4 as $ip) {
                    if (is_string($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
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
