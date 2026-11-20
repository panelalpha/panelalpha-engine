<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** A source host's addresses come from its A and AAAA records, and from the hosts lookup only when DNS gave none. */
class SourceFetchGuardResolveHostTest extends TestCase
{
    private const V6 = '2001:db8::5';

    public function test_the_a_and_aaaa_records_are_the_addresses_once_each(): void
    {
        $asked = [];
        $records = function (string $host) use (&$asked): array {
            $asked[] = $host;

            return [
                ['type' => 'A', 'ip' => TestNetwork::routable(7)],
                'not a record',
                ['type' => 'AAAA', 'ipv6' => self::V6],
                ['type' => 'A', 'ip' => TestNetwork::routable(7)],
                ['type' => 'A', 'ip' => 7],
                ['type' => 'CNAME', 'target' => 'wp.example.com'],
            ];
        };

        $addresses = SourceFetchGuard::resolveHost('wp.example.com', $records, fn (): never => $this->fail('the hosts lookup is not asked'));

        $this->assertSame([TestNetwork::routable(7), self::V6], $addresses);
        $this->assertSame(['wp.example.com'], $asked);
    }

    public function test_a_dns_lookup_that_fails_falls_back_to_the_hosts_lookup(): void
    {
        $addresses = SourceFetchGuard::resolveHost(
            'wp.example.com',
            static fn (): bool => false,
            static fn (string $host): array => $host === 'wp.example.com'
                ? [TestNetwork::routable(8), null, TestNetwork::routable(9), TestNetwork::routable(8)]
                : [],
        );

        $this->assertSame([TestNetwork::routable(8), TestNetwork::routable(9)], $addresses);
    }

    public function test_records_without_an_address_fall_back_to_the_hosts_lookup(): void
    {
        $addresses = SourceFetchGuard::resolveHost(
            'wp.example.com',
            static fn (): array => [['type' => 'A'], ['type' => 'AAAA', 'ipv6' => false]],
            static fn (): array => [TestNetwork::routable(10)],
        );

        $this->assertSame([TestNetwork::routable(10)], $addresses);
    }

    public function test_a_host_neither_lookup_knows_has_no_addresses(): void
    {
        $this->assertSame([], SourceFetchGuard::resolveHost('wp.example.com', static fn (): array => [], static fn (): bool => false));
    }
}
