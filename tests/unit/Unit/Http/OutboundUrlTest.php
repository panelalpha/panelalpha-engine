<?php

namespace Tests\Unit\Http;

use App\Lib\Http\OutboundUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/iam/'],
            'metadata ipv4-mapped' => ['http://[::ffff:169.254.169.254]/x'],
            'loopback' => ['http://127.0.0.1:5000/v2/_catalog'],
            'loopback ipv6' => ['https://[::1]/x'],
            'unspecified' => ['http://0.0.0.0/x'],
            'private 10/8' => ['http://10.0.0.5/x'],
            'private 192.168' => ['http://192.168.1.1/x'],
            'private 172.16' => ['http://172.16.0.1/x'],
            'carrier-grade nat' => ['http://100.64.0.1/x'],
            'link-local ipv6' => ['http://[fe80::1]/x'],
            'unique-local ipv6' => ['http://[fd00::1]/x'],
            'multicast' => ['http://224.0.0.1/x'],
            'reserved 240/4' => ['http://240.0.0.1/x'],
            'host docker internal' => ['http://host.docker.internal/x'],
            'documentation 192.0.2' => ['http://192.0.2.10/x'],
            'documentation 198.51.100' => ['http://198.51.100.10/x'],
            'documentation 203.0.113' => ['https://203.0.113.10:8443/acme/widgets.git'],
            'benchmarking 198.18' => ['http://198.19.0.1/x'],
            'ietf protocol assignments' => ['http://192.0.0.8/x'],
            'metadata ipv4-compatible' => ['http://[::169.254.169.254]/x'],
            'metadata through nat64' => ['http://[64:ff9b::169.254.169.254]/x'],
            'private through nat64' => ['http://[64:ff9b::a00:5]/x'],
            'documentation through nat64' => ['http://[64:ff9b::203.0.113.10]/x'],
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
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'host.docker.internal' ? ['172.17.0.1'] : []);

        $this->assertFalse(OutboundUrl::isAllowed($url), $url);

        $this->expectException(InvalidArgumentException::class);
        OutboundUrl::assertAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        return [
            'public ipv4' => ['https://93.184.216.34/x'],
            'public ipv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/x'],
            'public next to the documentation range' => ['https://203.0.114.1/x'],
            'public ipv6 next to the ietf block' => ['https://[2001:200::1]/x'],
            'public through nat64' => ['https://[64:ff9b::140.82.121.4]/x'],
            'scheme required' => ['https://example.com/file.zip'],
            'query and fragment' => ['https://example.com/a?b=c#d'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_a_public_address_is_allowed(string $url): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'example.com' ? ['93.184.216.34'] : []);

        $this->assertTrue(OutboundUrl::isAllowed($url), $url);
    }

    public function test_a_name_resolving_to_a_documentation_address_is_refused(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['203.0.113.10']);

        $this->assertFalse(OutboundUrl::isAllowed('https://gitlab.example.com:8443/acme/widgets.git'));
        $this->assertSame('203.0.113.10', OutboundUrl::refusedAddressOf('gitlab.example.com'));
    }

    public function test_allowing_private_addresses_allows_them_through_nat64_but_not_the_documentation_ranges(): void
    {
        OutboundUrl::setPolicyForTests(true, []);

        $this->assertTrue(OutboundUrl::isAllowed('http://[64:ff9b::a00:5]/x'));
        $this->assertTrue(OutboundUrl::isAllowed('http://[64:ff9b:1::a00:5]/x'));
        $this->assertTrue(OutboundUrl::isAllowed('http://[fec0::1]/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://[64:ff9b::169.254.169.254]/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://192.0.2.10/x'));
        $this->assertFalse(OutboundUrl::isAllowed('http://[2001:db8::1]/x'));
    }

    public function test_an_allow_listed_name_is_still_refused_at_a_documentation_or_reserved_address(): void
    {
        OutboundUrl::setPolicyForTests(false, ['git.internal']);
        $answer = '10.0.0.5';
        OutboundUrl::setResolverForTests(static function (string $host) use (&$answer): array {
            return [$answer];
        });

        $this->assertTrue(OutboundUrl::isAllowed('https://git.internal/x'));
        foreach (['64:ff9b::a00:5', 'fd00::1'] as $answer) {
            $this->assertTrue(OutboundUrl::isAllowed('https://git.internal/x'), $answer);
        }
        foreach (['198.51.100.7', '2001:db8::7', '64:ff9b::7f00:1', '2002:a9fe:a9fe::1'] as $answer) {
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
        // `localhost` resolves to 127.0.0.1 (and ::1); both are refused.
        $this->assertFalse(OutboundUrl::isAllowed('http://localhost/x'));
        // A literal public IPv6 address is allowed, its own range not refused.
        $this->assertTrue(OutboundUrl::isAllowed('http://[2001:4860:4860::8888]/x'));
    }

    public function test_the_pin_names_the_checked_address(): void
    {
        // A literal address has nothing to resolve; a refused one is refused.
        $this->assertNull(OutboundUrl::pin('https://93.184.216.34/x'));
        try {
            OutboundUrl::pin('http://169.254.169.254/x');
            $this->fail('a refused literal was pinned');
        } catch (InvalidArgumentException) {
        }

        OutboundUrl::setResolverForTests(static fn (string $host): array => ['93.184.216.34']);
        try {
            $this->assertSame('files.example.com:443:93.184.216.34', OutboundUrl::pin('https://files.example.com/x'));
            $this->assertSame('files.example.com:8080:93.184.216.34', OutboundUrl::pin('http://files.example.com:8080/x'));
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
        $answers = [['93.184.216.34'], ['169.254.169.254']];
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
