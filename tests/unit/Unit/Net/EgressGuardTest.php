<?php

namespace Tests\Unit\Net;

use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressGuard;
use App\Lib\Net\EgressRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'loopback' => ['http://127.0.0.1/x'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'unspecified' => ['http://0.0.0.0/x'],
            'private 10/8' => ['http://10.0.0.1/x'],
            'private 172.16/12' => ['http://172.16.5.1/x'],
            'private 192.168/16' => ['http://192.168.1.1/x'],
            'carrier-grade nat' => ['http://100.64.0.1/x'],
            'reserved 192.0.0/24' => ['http://192.0.0.1/x'],
            'benchmark 198.18/15' => ['http://198.18.0.1/x'],
            'multicast' => ['http://224.0.0.1/x'],
            'reserved 240/4' => ['http://240.0.0.1/x'],
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
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/x'],
            'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/x'],
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
            'public ipv4' => ['https://93.184.216.34/x'],
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
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['10.0.0.5']);
        EgressGuard::setPolicyForTests(false, []);

        $this->assertFalse(EgressGuard::isAllowed('https://git.internal/o/r.git'));

        EgressGuard::setPolicyForTests(true, []);
        $this->assertTrue(EgressGuard::isAllowed('https://git.internal/o/r.git'));
    }

    public function test_allowing_private_still_refuses_metadata_and_loopback(): void
    {
        EgressGuard::setPolicyForTests(true, []);

        foreach (['http://169.254.169.254/x', 'http://127.0.0.1/x', 'http://[::1]/x', 'http://[::ffff:169.254.169.254]/x'] as $url) {
            $this->assertFalse(EgressGuard::isAllowed($url), $url);
        }
    }

    public function test_an_allow_listed_host_bypasses_the_private_check(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['10.0.0.5']);
        EgressGuard::setPolicyForTests(false, ['git.internal']);

        $this->assertTrue(EgressGuard::allowsHost('git.internal'));
        $this->assertTrue(EgressGuard::isAllowed('https://git.internal/o/r.git'));
        $this->assertFalse(EgressGuard::isAllowed('https://other.internal/o/r.git'));
    }

    public function test_an_allow_listed_name_does_not_allow_a_literal_address(): void
    {
        EgressGuard::setPolicyForTests(false, ['10.0.0.5']);

        $this->assertFalse(EgressGuard::isAllowed('http://10.0.0.5/x'));
    }

    public function test_the_problem_names_the_field_and_the_refusal(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === 'git.internal'
            ? ['10.0.0.5']
            : ['93.184.216.34']);

        $problem = EgressGuard::problem('git_repo', 'https://git.internal/o/r.git');

        $this->assertSame('git_repo', $problem['field'] ?? null);
        $this->assertSame('host_refused', $problem['code'] ?? null);
        $this->assertStringContainsString('10.0.0.5', (string) ($problem['message'] ?? ''));
        $this->assertStringContainsString('does not fetch from', (string) ($problem['message'] ?? ''));
        $this->assertNull(EgressGuard::problem('git_repo', 'https://github.com/o/r.git'));
    }

    public function test_the_checked_address_is_pinned(): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => ['93.184.216.34']);

        $target = EgressGuard::assertAllowed('https://files.example.com/x');
        $this->assertSame('files.example.com', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame('files.example.com:443:93.184.216.34', $target->pin());

        $this->assertNull(EgressGuard::pin('https://93.184.216.34/x'));
    }
}
