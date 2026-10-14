<?php

namespace App\Lib\Http;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Lib\Net\EgressRefusedException;
use App\Lib\Net\ResolvedTarget;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A guard for URLs core is about to fetch, so a caller-supplied URL cannot
 * reach what core reaches over HTTP: the metadata service, the cache registry,
 * sites-phpmyadmin, host services.
 *
 * The rule is the tenant network's plus its IPv6 side, minus the private ranges
 * when the operator allows them ({@see config('egress.allow_private')}). An
 * allow-listed name ({@see config('egress.allow_hosts')}) skips only the
 * internal-address check: loopback, link-local, multicast and the reserved
 * ranges stay refused for it. A host is refused
 * when *any* of its A/AAAA records is internal, so a rebind mixing a public and
 * an internal address does not get through. Callers pin the checked address
 * ({@see pin}) and re-check every redirect hop.
 */
final class OutboundUrl
{
    /** Matches the deploy probe and the MCP download: enough for a release asset. */
    public const MAX_REDIRECTS = 5;

    /** Largest download either one accepts, in bytes. */
    public const MAX_DOWNLOAD_BYTES = 2147483648;

    /** The schemes the engine fetches over. */
    public const DEFAULT_SCHEMES = ['https', 'http'];

    /** The IPv4 side of what EGRESS_ALLOW_PRIVATE may allow, as network and prefix length. */
    private const PRIVATE_IPV4_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** The IPv4 ranges refused even for an allow-listed name: loopback, link-local, multicast, reserved. */
    private const ALWAYS_REFUSED_IPV4_RANGES = [
        '0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** The IPv6 counterparts of TenantNetwork::REFUSED_RANGES: network, prefix length, private. */
    private const REFUSED_IPV6_RANGES = [
        'loopback' => ['::1', 128, false],
        'unspecified' => ['::', 128, false],
        'IPv4-mapped' => ['::ffff:0:0', 96, false],
        'unique local' => ['fc00::', 7, true],
        'link local' => ['fe80::', 10, false],
        'multicast' => ['ff00::', 8, false],
    ];

    /** @var (callable(string): list<string>)|null a resolver a test can substitute for DNS */
    private static $resolver = null;

    /** @var ?bool a policy a test can substitute for config('egress.allow_private') */
    private static ?bool $allowPrivateForTests = null;

    /** @var ?list<string> a policy a test can substitute for config('egress.allow_hosts') */
    private static ?array $allowHostsForTests = null;

    /**
     * @param (callable(string): list<string>)|null $resolver
     */
    public static function setResolverForTests(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param ?bool $allowPrivate null restores the configured policy
     * @param ?list<string> $allowHosts null restores the configured policy
     */
    public static function setPolicyForTests(?bool $allowPrivate, ?array $allowHosts): void
    {
        self::$allowPrivateForTests = $allowPrivate;
        self::$allowHostsForTests = $allowHosts;
    }

    /** Whether an operator allows the engine to fetch private (RFC1918 and ULA) addresses. */
    public static function allowsPrivate(): bool
    {
        if (self::$allowPrivateForTests !== null) {
            return self::$allowPrivateForTests;
        }

        return (bool) self::configured('allow_private', false);
    }

    /** Whether this host name is on the operator's allow list; a literal address never is. */
    public static function allowsHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (self::$allowHostsForTests ?? self::configuredHosts() as $allowed) {
            if (strtolower(trim($allowed, '[]')) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $schemes
     * @throws EgressRefusedException when the scheme is not one of $schemes, the host is
     *         missing, or the host resolves to an address the engine must not contact
     */
    public static function assertAllowed(string $url, array $schemes = self::DEFAULT_SCHEMES): ResolvedTarget
    {
        return self::target($url, $schemes);
    }

    /** Whether a URL would be allowed, without throwing. */
    public static function isAllowed(string $url, array $schemes = self::DEFAULT_SCHEMES): bool
    {
        try {
            self::target($url, $schemes);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The checked target of $url: its host, port and every address it resolved
     * to. The address list is empty when the host is a literal.
     *
     * @param list<string> $schemes
     * @throws EgressRefusedException
     */
    public static function target(string $url, array $schemes = self::DEFAULT_SCHEMES): ResolvedTarget
    {
        [$host, $port] = self::parse($url, $schemes);

        return new ResolvedTarget($url, $host, $port, self::checkedAddresses($host));
    }

    /**
     * A `curl --resolve host:port:address` value pinning the connection to an
     * address checked here, or null for a literal address. Pinning the checked
     * answer keeps a rebinding DNS server from answering differently next time.
     *
     * @param list<string> $schemes
     * @throws EgressRefusedException when the host is refused or does not resolve
     */
    public static function pin(string $url, array $schemes = self::DEFAULT_SCHEMES): ?string
    {
        return self::target($url, $schemes)->pin();
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
     * The first address core must not contact: the host itself when it is a
     * literal, else the first such record it resolves to. Null when none is.
     */
    public static function refusedAddressOf(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::refusesAddress($host) ? $host : null;
        }

        return self::firstRefused(self::resolve($host), self::allowsHost($host));
    }

    /** IPv4 goes through {@see TenantNetwork::refuses()}; IPv6 mirrors its ranges. */
    public static function refusesAddress(string $address): bool
    {
        $address = trim($address, '[]');
        $allowPrivate = self::allowsPrivate();

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($allowPrivate && IpUtils::checkIp($address, self::PRIVATE_IPV4_RANGES)) {
                return false;
            }

            return TenantNetwork::refuses($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::refusesIpv6($address, $allowPrivate);
        }

        return true;
    }

    /** What stays refused for an allow-listed name: everything but the private ranges. */
    private static function refusesForAllowedHost(string $address): bool
    {
        $address = trim($address, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return IpUtils::checkIp($address, self::ALWAYS_REFUSED_IPV4_RANGES);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::refusesIpv6($address, true);
        }

        return true;
    }

    /** IPv4-mapped is refused whole: it is how an IPv4 metadata address is often written. */
    private static function refusesIpv6(string $address, bool $allowPrivate): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        foreach (self::REFUSED_IPV6_RANGES as [$network, $bits, $private]) {
            if ($private && $allowPrivate) {
                continue;
            }
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

    /**
     * The host and effective port of a URL, or a refusal when it has no host or
     * a scheme the engine does not fetch over.
     *
     * @param list<string> $schemes
     * @return array{0: string, 1: int}
     * @throws EgressRefusedException
     */
    private static function parse(string $url, array $schemes): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, $schemes, true) || $host === '') {
            throw new EgressRefusedException(
                'The URL scheme must be one of ' . implode(', ', $schemes) . ', and it must name a host.'
            );
        }

        $host = trim($host, '[]');
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return [$host, $port];
    }

    /**
     * Every address $host resolves to, each checked; empty when it is a literal
     * address (checked too, nothing to resolve). An allow-listed name is
     * resolved and checked against the narrower rule.
     *
     * @return list<string>
     * @throws EgressRefusedException
     */
    private static function checkedAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::refusesAddress($host)) {
                throw self::refusal($host);
            }

            return [];
        }

        return self::resolvedAndChecked($host, self::allowsHost($host));
    }

    /**
     * @return non-empty-list<string>
     * @throws EgressRefusedException
     */
    private static function resolvedAndChecked(string $host, bool $allowListed): array
    {
        $addresses = self::resolve($host);
        if ($addresses === []) {
            throw new \InvalidArgumentException("The host {$host} could not be resolved.");
        }
        $refused = self::firstRefused($addresses, $allowListed);
        if ($refused !== null) {
            throw self::refusal($refused, $host, $allowListed);
        }

        return $addresses;
    }

    /** @param list<string> $addresses */
    private static function firstRefused(array $addresses, bool $allowListed = false): ?string
    {
        foreach ($addresses as $address) {
            if ($allowListed ? self::refusesForAllowedHost($address) : self::refusesAddress($address)) {
                return $address;
            }
        }

        return null;
    }

    private static function refusal(string $address, ?string $host = null, bool $allowListed = false): EgressRefusedException
    {
        $where = $host === null || $host === $address
            ? "{$address} is an address this engine does not fetch from."
            : "{$host} resolves to {$address}, which this engine does not fetch from.";
        $hint = $allowListed
            ? ' EGRESS_ALLOW_HOSTS does not allow loopback, link-local, multicast or reserved addresses.'
            : ' Set EGRESS_ALLOW_PRIVATE=true to allow a private address.';

        return new EgressRefusedException($where . $hint);
    }

    /** A comma list from config, tolerant of a bare PHPUnit run with no booted application. */
    private static function configuredHosts(): array
    {
        $value = self::configured('allow_hosts', []);

        return is_array($value) ? array_values(array_filter($value, static fn (mixed $h): bool => is_string($h))) : [];
    }

    private static function configured(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('egress.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
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
