<?php

namespace Tests\Unit\Http;

use App\Lib\Http\OutboundUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * engine#785: a token narrowed to file_upload / files fetch must not be able
 * to read the cloud metadata service, the cache registry, sites-phpmyadmin or
 * host services. The guard refuses every address TenantNetwork::refuses()
 * refuses, plus IPv6, and every A/AAAA record a hostname resolves to.
 */
class OutboundUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'cloud metadata' => ['http://'.TestNetwork::METADATA.'/latest/meta-data/iam/'],
            'metadata ipv4-mapped' => ['http://[::ffff:'.TestNetwork::METADATA.']/x'],
            'loopback' => ['http://'.TestNetwork::LOOPBACK.':5000/v2/_catalog'],
            'loopback ipv6' => ['https://[::1]/x'],
            'unspecified' => ['http://'.TestNetwork::ANY.'/x'],
            'private 10/8' => ['http://'.TestNetwork::private(5).'/x'],
            'private 192.168' => ['http://'.TestNetwork::private2(1).'/x'],
            'private 172.16' => ['http://'.TestNetwork::PRIVATE_172.'/x'],
            'carrier-grade nat' => ['http://'.TestNetwork::CGNAT.'/x'],
            'link-local ipv6' => ['http://[fe80::1]/x'],
            'unique-local ipv6' => ['http://[fd00::1]/x'],
            'multicast' => ['http://'.TestNetwork::MULTICAST.'/x'],
            'reserved 240/4' => ['http://'.TestNetwork::FUTURE_USE.'/x'],
            'host docker internal' => ['http://host.docker.internal/x'],
            'documentation 192.0.2' => ['http://'.TestNetwork::DOCUMENTATION_1.'/x'],
            'documentation 198.51.100' => ['http://'.TestNetwork::DOCUMENTATION_2.'/x'],
            'documentation 203.0.113' => ['https://'.TestNetwork::DOCUMENTATION_3.':8443/acme/widgets.git'],
            'benchmarking 198.18' => ['http://'.TestNetwork::add(TestNetwork::BENCHMARK, 65536).'/x'],
            'ietf protocol assignments' => ['http://'.TestNetwork::IETF_PROTOCOL.'/x'],
            'metadata ipv4-compatible' => ['http://[::'.TestNetwork::METADATA.']/x'],
            'metadata through nat64' => ['http://[64:ff9b::'.TestNetwork::METADATA.']/x'],
            'private through nat64' => ['http://[64:ff9b::a00:5]/x'],
            'documentation through nat64' => ['http://[64:ff9b::'.TestNetwork::DOCUMENTATION_3.']/x'],
            'local-use nat64' => ['http://[64:ff9b:1::8c52:7904]/x'],
            'discard-only ipv6' => ['http://[100::1]/x'],
            'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/x'],
            'benchmarking ipv6' => ['http://[2001:2::1]/x'],
            'documentation ipv6' => ['http://[2001:db8::1]/x'],
            'documentation ipv6 3fff' => ['http://[3fff:fff::1]/x'],
            '6to4' => ['http://[2002:a9fe:a9fe::1]/x'],
            'segment routing' => ['http://[5f00::1]/x'],
            'site-local ipv6' => ['http://[fec0::1]/x'],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_internal_address_is_refused(string $url): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'host.docker.internal' ? [TestNetwork::DOCKER_BRIDGE_GATEWAY] : []);

        $this->assertFalse(OutboundUrl::isAllowed($url), $url);

        $this->expectException(InvalidArgumentException::class);
        OutboundUrl::assertAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        return [
            'public ipv4' => ['https://'.TestNetwork::routable(34).'/x'],
            'public ipv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/x'],
            'public next to the documentation range' => ['https://'.TestNetwork::add(TestNetwork::DOCUMENTATION_3, 256).'/x'],
            'public ipv6 next to the ietf block' => ['https://[2001:200::1]/x'],
            'public through nat64' => ['https://[64:ff9b::'.TestNetwork::routable(4).']/x'],
            'scheme required' => ['https://example.com/file.zip'],
            'query and fragment' => ['https://example.com/a?b=c#d'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_a_public_address_is_allowed(string $url): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'example.com' ? [TestNetwork::routable(34)] : []);

        $this->assertTrue(OutboundUrl::isAllowed($url), $url);
    }

    public function test_a_name_resolving_to_a_documentation_address_is_refused(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::DOCUMENTATION_3]);

        $this->assertFalse(OutboundUrl::isAllowed('https://gitlab.example.com:8443/acme/widgets.git'));
        $this->assertSame(TestNetwork::DOCUMENTATION_3, OutboundUrl::refusedAddressOf('gitlab.example.com'));
    }

    public function test_allowing_private_addresses_allows_them_through_nat64_but_not_the_documentation_ranges(): void
    {
        OutboundUrl::setPolicyForTests(true, []);

        $this->assertTrue(OutboundUrl::isAllowed('http://[64:ff9b::a00:5]/x'));
        $this->assertTrue(OutboundUrl::isAllowed('http://[64:ff9b:1::a00:5]/x'));
        $this->assertTrue(OutboundUrl::isAllowed('http://[fec0::1]/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://[64:ff9b::'.TestNetwork::METADATA.']/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://'.TestNetwork::DOCUMENTATION_1.'/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://[2001:db8::1]/x'));
    }

    public function test_an_allow_listed_name_is_still_refused_at_a_documentation_or_reserved_address(): void
    {
        OutboundUrl::setPolicyForTests(false, ['git.internal']);
        $answer = TestNetwork::private(5);
        OutboundUrl::setResolverForTests(static function (string $host) use (&$answer): array {
            return [$answer];
        });

        $this->assertTrue(OutboundUrl::isAllowed('https://git.internal/x'));
        foreach (['64:ff9b::a00:5', 'fd00::1'] as $answer) {
            $this->assertTrue(OutboundUrl::isAllowed('https://git.internal/x'), $answer);
        }
        foreach ([TestNetwork::DOCUMENTATION_2, '2001:db8::7', '64:ff9b::7f00:1', '2002:a9fe:a9fe::1'] as $answer) {
            $this->assertFalse(OutboundUrl::isAllowed('https://git.internal/x'), $answer);
        }
    }

    public function test_a_bad_scheme_or_missing_host_is_refused(): void
    {
        foreach (['file:///etc/passwd', 'gopher://example.com/', 'https:///x'] as $url) {
            $this->assertFalse(OutboundUrl::isAllowed($url), $url);
        }
    }

    /**
     * A hostname that resolves to both a public and an internal address is
     * refused: the connector could otherwise be steered to the internal one.
     */
    public function test_a_hostname_resolving_to_an_internal_address_is_refused(): void
    {
        // `localhost` resolves to the IPv4 loopback (and ::1); both are refused.
        $this->assertFalse(OutboundUrl::isAllowed('http://localhost/x'));
        // A literal public IPv6 address is allowed, its own range not refused.
        $this->assertTrue(OutboundUrl::isAllowed('http://[2001:4860:4860::8888]/x'));
    }

    public function test_the_pin_names_the_checked_address(): void
    {
        // A literal address has nothing to resolve; a refused one is refused.
        $this->assertNull(OutboundUrl::pin('https://'.TestNetwork::routable(34).'/x'));
        try {
            OutboundUrl::pin('http://'.TestNetwork::METADATA.'/x');
            $this->fail('a refused literal was pinned');
        } catch (InvalidArgumentException) {
        }

        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(34)]);
        try {
            $this->assertSame('files.example.com:443:'.TestNetwork::routable(34), OutboundUrl::pin('https://files.example.com/x'));
            $this->assertSame('files.example.com:8080:'.TestNetwork::routable(34), OutboundUrl::pin('http://files.example.com:8080/x'));
        } finally {
            OutboundUrl::setResolverForTests(null);
        }
    }

    /**
     * DNS rebinding: the check sees a public answer, the connection's own
     * lookup an internal one. The pin resolves once and checks what it pins.
     */
    public function test_the_pin_checks_the_address_it_pins(): void
    {
        $answers = [[TestNetwork::routable(34)], [TestNetwork::METADATA]];
        OutboundUrl::setResolverForTests(static function (string $host) use (&$answers): array {
            return array_shift($answers) ?? [];
        });

        try {
            OutboundUrl::assertAllowed('http://rebind.example.com/x');
            $this->expectException(InvalidArgumentException::class);
            OutboundUrl::pin('http://rebind.example.com/x');
        } finally {
            OutboundUrl::setResolverForTests(null);
        }
    }
}
