<?php

namespace App\Lib\Http;

use App\Lib\Deploy\Dind\TenantNetwork;
use InvalidArgumentException;

/**
 * A guard for URLs core is about to download, so a file-upload token cannot
 * read what core reaches over HTTP: the metadata service, the cache registry,
 * sites-phpmyadmin, host services.
 *
 * The rule is the tenant network's plus its IPv6 side. A host is refused when
 * *any* of its A/AAAA records is internal, so a rebind mixing a public and an
 * internal address does not get through. Callers pin the checked address
 * ({@see pin}) and re-check every redirect hop.
 */
final class OutboundUrl
{
    /** Matches the deploy probe and the MCP download: enough for a release asset. */
    public const MAX_REDIRECTS = 5;

    /** Largest download either one accepts, in bytes. */
    public const MAX_DOWNLOAD_BYTES = 2147483648;

    /** The IPv6 counterparts of TenantNetwork::REFUSED_RANGES, as network and prefix length. */
    private const REFUSED_IPV6_RANGES = [
        'loopback' => ['::1', 128],
        'unspecified' => ['::', 128],
        'IPv4-mapped' => ['::ffff:0:0', 96],
        'unique local' => ['fc00::', 7],
        'link local' => ['fe80::', 10],
        'multicast' => ['ff00::', 8],
    ];

    /** @var (callable(string): list<string>)|null a resolver a test can substitute for DNS */
    private static $resolver = null;

    /**
     * @param (callable(string): list<string>)|null $resolver
     */
    public static function setResolverForTests(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @throws InvalidArgumentException when the scheme is not http(s), the host is
     *         missing, or the host resolves to an address core must not contact
     */
    public static function assertAllowed(string $url): void
    {
        self::checkedAddresses($url);
    }

    /** Whether a URL would be allowed, without throwing. */
    public static function isAllowed(string $url): bool
    {
        try {
            self::assertAllowed($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Every A and AAAA record for a host, as strings. IPv6 is asked of
     * `dns_get_record` because `gethostbynamel` is IPv4-only.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values(array_unique((self::$resolver)($host)));
        }

        $ipv4 = @gethostbynamel($host);
        $addresses = [...(is_array($ipv4) ? $ipv4 : []), ...self::ipv6Addresses($host)];

        return array_values(array_unique($addresses));
    }

    /** @return list<string> */
    private static function ipv6Addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (is_string($record['ipv6'] ?? null) && $record['ipv6'] !== '') {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * A `curl --resolve host:port:address` value pinning the connection to an
     * address checked here, or null for a literal address. Pinning the checked
     * answer keeps a rebinding DNS server from answering differently next time.
     *
     * @throws InvalidArgumentException when the host is refused or does not resolve
     */
    public static function pin(string $url): ?string
    {
        $addresses = self::checkedAddresses($url);
        if ($addresses === null) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $address = $addresses[0];
        if (str_contains($address, ':')) {
            $address = '[' . $address . ']';
        }
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);

        return "{$host}:{$port}:{$address}";
    }

    /**
     * The first address core must not contact: the host itself when it is a
     * literal, else the first such record it resolves to. Null when none is.
     */
    public static function refusedAddressOf(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::refusesAddress($host) ? $host : null;
        }

        return self::firstRefused(self::resolve($host));
    }

    /**
     * Every address the URL's host resolves to, each checked; null when the
     * host is a literal address (checked too, nothing to resolve).
     *
     * @return ?non-empty-list<string>
     * @throws InvalidArgumentException
     */
    private static function checkedAddresses(string $url): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('The URL must be http:// or https:// with a host.');
        }

        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::refusesAddress($host)) {
                throw self::refusal($host);
            }

            return null;
        }

        return self::resolvedAndChecked($host);
    }

    /**
     * @return non-empty-list<string>
     * @throws InvalidArgumentException
     */
    private static function resolvedAndChecked(string $host): array
    {
        $addresses = self::resolve($host);
        if ($addresses === []) {
            throw new InvalidArgumentException("The host {$host} could not be resolved.");
        }
        $refused = self::firstRefused($addresses);
        if ($refused !== null) {
            throw self::refusal($refused);
        }

        return $addresses;
    }

    /** @param list<string> $addresses */
    private static function firstRefused(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            if (self::refusesAddress($address)) {
                return $address;
            }
        }

        return null;
    }

    /** IPv4 goes through {@see TenantNetwork::refuses()}; IPv6 mirrors its ranges. */
    public static function refusesAddress(string $address): bool
    {
        $address = trim($address, '[]');

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return TenantNetwork::refuses($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::refusesIpv6($address);
        }

        return true;
    }

    /** IPv4-mapped is refused whole: it is how an IPv4 metadata address is often written. */
    private static function refusesIpv6(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        foreach (self::REFUSED_IPV6_RANGES as [$network, $bits]) {
            if (self::inRange($packed, (string) inet_pton($network), $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private static function refusal(string $address): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "The URL resolves to {$address}, a private, loopback, link-local or reserved address. "
            . 'The engine does not fetch those.'
        );
    }

    /** Resolve a possibly-relative Location against the URL it came from. */
    public static function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . $dir . $location;
    }
}
