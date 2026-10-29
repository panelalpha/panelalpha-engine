<?php

namespace Tests\Support;

/**
 * Every IP address a test uses. A test writes no address literal (a guard test
 * enforces it): scenario addresses come from the `TEST_NET_*` networks in
 * tests/test-network.env, shared with the API and shell tests, which an
 * environment variable of the same name overrides, and addresses fixed by a
 * standard or by the engine are the constants below.
 *
 * A scenario address keeps its host number: `public(7)` is the 7th address of
 * the public test network, so a test reads as it did with literals.
 */
final class TestNetwork
{
    public const ANY = '0.0.0.0';

    public const ANY_CIDR = '0.0.0.0/0';

    /** An address in RFC 1122 "this network" (0.0.0.0/8), which no host uses as its own. */
    public const THIS_NETWORK = '0.0.0.1';

    public const LOOPBACK = '127.0.0.1';

    public const LOOPBACK_CIDR = '127.0.0.0/8';

    /** The address Debian's /etc/hosts gives the machine's own hostname. */
    public const HOSTNAME_LOOPBACK = '127.0.1.1';

    public const LINK_LOCAL_CIDR = '169.254.0.0/16';

    /** Where cloud metadata services answer. */
    public const METADATA = '169.254.169.254';

    public const PRIVATE_10_CIDR = '10.0.0.0/8';

    public const PRIVATE_172_CIDR = '172.16.0.0/12';

    public const PRIVATE_192_CIDR = '192.168.0.0/16';

    /** A sample of the RFC 1918 10.0.0.0/8 range. */
    public const PRIVATE_10 = '10.0.0.1';

    /** A sample of the RFC 1918 172.16.0.0/12 range. */
    public const PRIVATE_172 = '172.16.0.1';

    /** A sample of the RFC 1918 192.168.0.0/16 range. */
    public const PRIVATE_192 = '192.168.0.1';

    /** RFC 6598 shared address space (carrier-grade NAT). */
    public const CGNAT_CIDR = '100.64.0.0/10';

    public const CGNAT = '100.64.0.1';

    /** RFC 5737 documentation ranges, for a check whose subject is that they are refused. */
    public const DOCUMENTATION_1 = '192.0.2.10';

    public const DOCUMENTATION_2 = '198.51.100.10';

    public const DOCUMENTATION_3 = '203.0.113.10';

    /** RFC 6890 IETF protocol assignments. */
    public const IETF_PROTOCOL = '192.0.0.8';

    /** RFC 2544 benchmarking. */
    public const BENCHMARK = '198.18.0.1';

    public const MULTICAST = '224.0.0.1';

    /** RFC 1112 reserved for future use. */
    public const FUTURE_USE = '240.0.0.1';

    public const MASK_24 = '255.255.255.0';

    /** Docker's DNS inside every container network. */
    public const DOCKER_DNS = '127.0.0.11';

    /** Docker's default bridge gateway (docker0). */
    public const DOCKER_BRIDGE_GATEWAY = '172.17.0.1';

    public const DOCKER_BRIDGE_CIDR = '172.17.0.0/16';

    /** systemd-resolved's stub listener. */
    public const RESOLVED_STUB = '127.0.0.53';

    /** The engine's DNS proxy, published by the sites-dns service. */
    public const ENGINE_DNS_PROXY = '127.0.0.54';

    /** The visitor address the engine's health probe claims in X-Forwarded-For (AppHealth's private PROBE_CLIENT). */
    public const HEALTH_PROBE_CLIENT = '192.0.2.1';

    /** The tenant network's default (docker-compose.yml's TENANT_NETWORK_PREFIX), the first /16 tenant-network-firewall.sh tries. */
    public const TENANT_NETWORK_CIDR = '10.200.0.0/16';

    /** An address on the public internet; the default network is documentation only (RFC 5737). */
    public static function public(int $host = 1): string
    {
        return self::host('TEST_NET_PUBLIC', $host);
    }

    /** A second public network, for a test that needs addresses of two parties. */
    public static function public2(int $host = 1): string
    {
        return self::host('TEST_NET_PUBLIC_2', $host);
    }

    /** A third public network. */
    public static function public3(int $host = 1): string
    {
        return self::host('TEST_NET_PUBLIC_3', $host);
    }

    /** A public address that also passes checks refusing documentation ranges. */
    public static function routable(int $host = 1): string
    {
        return self::host('TEST_NET_ROUTABLE', $host);
    }

    /** A private (RFC 1918) address, as a host's own LAN address. */
    public static function private(int $host = 1): string
    {
        return self::host('TEST_NET_PRIVATE', $host);
    }

    /** A second private network. */
    public static function private2(int $host = 1): string
    {
        return self::host('TEST_NET_PRIVATE_2', $host);
    }

    /** An address on a container network. */
    public static function container(int $host = 1): string
    {
        return self::host('TEST_NET_CONTAINER', $host);
    }

    /** A second container network, as a project's own network beside the engine's. */
    public static function container2(int $host = 1): string
    {
        return self::host('TEST_NET_CONTAINER_2', $host);
    }

    /**
     * A configured network in CIDR form; $name is the variable without `TEST_NET_`,
     * so `net('PUBLIC_2')` is TEST_NET_PUBLIC_2.
     */
    public static function net(string $name): string
    {
        [$base, $bits] = self::network('TEST_NET_'.$name);

        return $base.'/'.$bits;
    }

    /** The address $delta places after $address, to reach a neighbour of a configured address. */
    public static function add(string $address, int $delta): string
    {
        return (string) long2ip(self::toLong($address) + $delta);
    }

    /** The network of $bits that holds $address, in CIDR form. */
    public static function prefix(string $address, int $bits): string
    {
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip(self::toLong($address) & $mask).'/'.$bits;
    }

    private static function host(string $variable, int $host): string
    {
        [$base, $bits] = self::network($variable);
        $size = 2 ** (32 - $bits);
        if ($host < 0 || $host >= $size) {
            throw new \LogicException("Host {$host} is outside {$variable} ({$base}/{$bits}).");
        }

        return (string) long2ip(self::toLong($base) + $host);
    }

    /** @return array{string, int} */
    private static function network(string $variable): array
    {
        $value = getenv($variable);
        if ($value === false) {
            $value = self::configured()[$variable] ?? null;
        }
        if (! is_string($value) || preg_match('~\A(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})\z~', $value, $m) !== 1
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $m[2] > 30) {
            throw new \LogicException("{$variable} must be an IPv4 network such as 203.0.113.0/24; define it in tests/test-network.env.");
        }

        return [$m[1], (int) $m[2]];
    }

    /** @return array<string, string> tests/test-network.env, read once */
    private static function configured(): array
    {
        static $values = null;

        return $values ??= self::parse((string) file_get_contents(dirname(__DIR__, 2).'/test-network.env'));
    }

    /** @return array<string, string> */
    private static function parse(string $env): array
    {
        preg_match_all('~^(TEST_NET_[A-Z0-9_]+)=(\S+)$~m', $env, $matches);

        return array_combine($matches[1], $matches[2]);
    }

    private static function toLong(string $address): int
    {
        $long = ip2long($address);
        if ($long === false) {
            throw new \LogicException("{$address} is not an IPv4 address.");
        }

        return $long;
    }
}
