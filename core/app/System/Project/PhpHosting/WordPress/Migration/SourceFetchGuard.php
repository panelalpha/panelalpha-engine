<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;

/**
 * Rejects source URLs the migration worker must not fetch: non-HTTP schemes,
 * loopback, and link-local (including cloud metadata). Private LAN addresses
 * stay allowed so a lab WordPress on RFC1918 can still be migrated.
 */
final class SourceFetchGuard
{
    /**
     * Check the URL and every address its host resolves to.
     *
     * @return list<string> the checked addresses, the first of which a request should connect to
     */
    public static function assertAllowed(string $url, ?callable $resolve = null): array
    {
        $host = self::assertShape($url);
        $addresses = self::addressesFor($host, $resolve);
        foreach ($addresses as $ip) {
            self::assertAddressAllowed($ip);
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

    public static function assertAddressAllowed(string $ip): void
    {
        if (self::isBlockedAddress($ip)) {
            throw new RuntimeException(
                'Source WordPress URL must not target a loopback or link-local address'
            );
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

    private static function isBlockedAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 16) {
            $v4Mapped = str_repeat("\0", 10)."\xff\xff";
            if (substr($packed, 0, 12) === $v4Mapped) {
                $mapped = inet_ntop(substr($packed, 12));

                return is_string($mapped) && self::isBlockedAddress($mapped);
            }
            if ($packed === inet_pton('::1') || $packed === str_repeat("\0", 16)) {
                return true;
            }
            $first = ord($packed[0]);
            $second = ord($packed[1]);

            return $first === 0xFE && ($second & 0xC0) === 0x80;
        }

        if (strlen($packed) !== 4) {
            return true;
        }

        $long = unpack('N', $packed);
        if (! is_array($long)) {
            return true;
        }
        $value = $long[1];
        if (($value & 0xFF000000) === 0x7F000000) {
            return true;
        }
        if (($value & 0xFFFF0000) === 0xA9FE0000) {
            return true;
        }

        return ($value & 0xFF000000) === 0;
    }
}
