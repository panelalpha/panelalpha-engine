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
        ];
    }

    #[DataProvider('refused')]
    public function test_an_internal_address_is_refused(string $url): void
    {
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
            'scheme required' => ['https://example.com/file.zip'],
            'query and fragment' => ['https://example.com/a?b=c#d'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_a_public_address_is_allowed(string $url): void
    {
        $this->assertTrue(OutboundUrl::isAllowed($url), $url);
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
