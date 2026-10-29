<?php

namespace Tests\Unit\Net;

use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressGuard;
use App\Lib\Net\EgressRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The engine's egress guard: every A and AAAA record of a user-supplied URL is
 * checked, so a URL that rebinds to the metadata service, a Docker or tenant
 * network, or the loopback is refused before a connection is made. RFC1918 and
 * ULA are refused by default and allowed only by config.
 */
class EgressGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        EgressGuard::setPolicyForTests(null, null);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function refusedIpv4(): array
    {
        return [
            'loopback' => ['http://'.TestNetwork::LOOPBACK.'/x'],
            'cloud metadata' => ['http://'.TestNetwork::METADATA.'/latest/meta-data/'],
            'unspecified' => ['http://'.TestNetwork::ANY.'/x'],
            'private 10/8' => ['http://'.TestNetwork::PRIVATE_10.'/x'],
            'private 172.16/12' => ['http://'.TestNetwork::PRIVATE_172.'/x'],
            'private 192.168/16' => ['http://'.TestNetwork::PRIVATE_192.'/x'],
            'carrier-grade nat' => ['http://'.TestNetwork::CGNAT.'/x'],
            'reserved 192.0.0/24' => ['http://'.TestNetwork::IETF_PROTOCOL.'/x'],
            'benchmark 198.18/15' => ['http://'.TestNetwork::BENCHMARK.'/x'],
            'multicast' => ['http://'.TestNetwork::MULTICAST.'/x'],
            'reserved 240/4' => ['http://'.TestNetwork::FUTURE_USE.'/x'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function refusedIpv6(): array
    {
        return [
            'loopback' => ['http://[::1]/x'],
            'unspecified' => ['http://[::]/x'],
            'unique local' => ['http://[fd00::1]/x'],
            'link local' => ['http://[fe80::1]/x'],
            'multicast' => ['http://[ff02::1]/x'],
            'ipv4-mapped loopback' => ['http://[::ffff:'.TestNetwork::LOOPBACK.']/x'],
            'ipv4-mapped metadata' => ['http://[::ffff:'.TestNetwork::METADATA.']/x'],
        ];
    }

    #[DataProvider('refusedIpv4')]
    public function test_an_internal_ipv4_address_is_refused(string $url): void
    {
        $this->assertFalse(EgressGuard::isAllowed($url), $url);

        $this->expectException(EgressRefusedException::class);
        EgressGuard::assertAllowed($url);
    }

    #[DataProvider('refusedIpv6')]
    public function test_an_internal_ipv6_address_is_refused(string $url): void
    {
        $this->assertFalse(EgressGuard::isAllowed($url), $url);

        $this->expectException(EgressRefusedException::class);
        EgressGuard::assertAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function allowedPublic(): array
    {
        return [
            'public ipv4' => ['https://'.TestNetwork::routable(34).'/x'],
            'public ipv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/x'],
            'ipv6 documentation' => ['https://[2001:4860:4860::8888]/x'],
        ];
    }

    #[DataProvider('allowedPublic')]
    public function test_a_public_address_is_allowed(string $url): void
    {
        $this->assertTrue(EgressGuard::isAllowed($url), $url);
    }

    /** @return array<string, array{string}> */
    public static function refusedScheme(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/x'],
            'git ext transport' => ['ext::sh -c id'],
            'gopher' => ['gopher://example.com/'],
            'no host' => ['https:///x'],
        ];
    }

    #[DataProvider('refusedScheme')]
    public function test_a_scheme_the_engine_does_not_fetch_is_refused(string $url): void
    {
        $this->assertFalse(EgressGuard::isAllowed($url), $url);

        $problem = EgressGuard::problem('repo_url', $url);
        $this->assertSame('host_refused', $problem['code'] ?? null);
        $this->assertSame('repo_url', $problem['field'] ?? null);
    }

    public function test_private_is_allowed_only_when_the_operator_allows_it(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::PRIVATE_10]);
        EgressGuard::setPolicyForTests(false, []);

        $this->assertFalse(EgressGuard::isAllowed('https://git.internal/o/r.git'));

        EgressGuard::setPolicyForTests(true, []);
        $this->assertTrue(EgressGuard::isAllowed('https://git.internal/o/r.git'));
    }

    public function test_allowing_private_still_refuses_metadata_and_loopback(): void
    {
        EgressGuard::setPolicyForTests(true, []);

        foreach (['http://'.TestNetwork::METADATA.'/x', 'http://'.TestNetwork::LOOPBACK.'/x', 'http://[::1]/x', 'http://[::ffff:'.TestNetwork::METADATA.']/x'] as $url) {
            $this->assertFalse(EgressGuard::isAllowed($url), $url);
        }
    }

    public function test_an_allow_listed_host_bypasses_the_private_check(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::PRIVATE_10]);
        EgressGuard::setPolicyForTests(false, ['git.internal']);

        $this->assertTrue(EgressGuard::allowsHost('git.internal'));
        $this->assertTrue(EgressGuard::isAllowed('https://git.internal/o/r.git'));
        $this->assertFalse(EgressGuard::isAllowed('https://other.internal/o/r.git'));
    }

    /** @return array<string, array{string}> */
    public static function addressesRefusedEvenWhenAllowListed(): array
    {
        return [
            'loopback' => [TestNetwork::LOOPBACK],
            'cloud metadata' => [TestNetwork::METADATA],
            'unspecified' => [TestNetwork::ANY],
            'multicast' => [TestNetwork::MULTICAST],
            'reserved 240/4' => [TestNetwork::FUTURE_USE],
            'ipv6 loopback' => ['::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv4-mapped metadata' => ['::ffff:'.TestNetwork::METADATA],
        ];
    }

    #[DataProvider('addressesRefusedEvenWhenAllowListed')]
    public function test_an_allow_listed_host_still_refuses_loopback_link_local_and_reserved(string $address): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::PRIVATE_10, $address]);
        EgressGuard::setPolicyForTests(false, ['git.internal']);

        $this->assertFalse(EgressGuard::isAllowed('https://git.internal/o/r.git'));
        $this->assertSame($address, OutboundUrl::refusedAddressOf('git.internal'));
    }

    public function test_an_allow_listed_host_is_pinned_to_its_checked_address(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['fd00::5']);
        EgressGuard::setPolicyForTests(false, ['git.internal']);

        $this->assertSame('git.internal:443:[fd00::5]', EgressGuard::pin('https://git.internal/o/r.git'));
        $this->assertNull(OutboundUrl::refusedAddressOf('git.internal'));
    }

    public function test_an_allow_listed_name_does_not_allow_a_literal_address(): void
    {
        EgressGuard::setPolicyForTests(false, [TestNetwork::PRIVATE_10]);

        $this->assertFalse(EgressGuard::isAllowed('http://'.TestNetwork::PRIVATE_10.'/x'));
    }

    public function test_the_problem_names_the_field_and_the_refusal(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'git.internal'
            ? [TestNetwork::PRIVATE_10]
            : [TestNetwork::routable(34)]);

        $problem = EgressGuard::problem('git_repo', 'https://git.internal/o/r.git');

        $this->assertSame('git_repo', $problem['field'] ?? null);
        $this->assertSame('host_refused', $problem['code'] ?? null);
        $this->assertStringContainsString(TestNetwork::PRIVATE_10, (string) ($problem['message'] ?? ''));
        $this->assertStringContainsString('does not fetch from', (string) ($problem['message'] ?? ''));
        $this->assertNull(EgressGuard::problem('git_repo', 'https://github.com/o/r.git'));
    }

    public function test_a_host_that_does_not_resolve_is_refused(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => []);

        foreach (['http://0x7f000001:9/x.git', 'http://017700000001/x.git', 'https://no-such-host.invalid/o/r.git'] as $url) {
            $problem = EgressGuard::problem('repo_url', $url);

            $this->assertSame('host_refused', $problem['code'] ?? null, $url);
            $this->assertSame('repo_url', $problem['field'] ?? null, $url);
            $this->assertStringContainsString('could not be resolved', (string) ($problem['message'] ?? ''), $url);
        }
    }

    public function test_the_checked_address_is_pinned(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(34)]);

        $target = EgressGuard::assertAllowed('https://files.example.com/x');
        $this->assertSame('files.example.com', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame('files.example.com:443:'.TestNetwork::routable(34), $target->pin());

        $this->assertNull(EgressGuard::pin('https://'.TestNetwork::routable(34).'/x'));
    }
}
