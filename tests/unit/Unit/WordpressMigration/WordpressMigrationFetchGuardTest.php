<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestNetwork;

/**
 * A source URL must lead to a public address that is not the Engine's own,
 * and every refused address gets the same answer. Private ranges are allowed
 * only by the operator (EGRESS_ALLOW_PRIVATE, or EGRESS_ALLOW_HOSTS for a name).
 */
class WordpressMigrationFetchGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    public function test_https_and_public_hosts_are_allowed(): void
    {
        $this->assertSame([TestNetwork::routable(34)], SourceFetchGuard::assertAllowed('https://example.com/blog', $this->policy(), $this->dns()));
        $this->assertSame([TestNetwork::routable(69)], SourceFetchGuard::assertAllowed('http://'.TestNetwork::routable(69).':8080/wp', $this->policy(), $this->dns()));
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http or https');

        SourceFetchGuard::assertAllowed('file:///etc/passwd', $this->policy(), static fn (): array => []);
    }

    #[DataProvider('refusedTargets')]
    public function test_internal_and_engine_addresses_are_refused_with_one_answer(string $url): void
    {
        try {
            SourceFetchGuard::assertAllowed($url, $this->policy(), $this->dns());
            $this->fail("{$url} must be refused.");
        } catch (RuntimeException $e) {
            $this->assertSame(SourceFetchGuard::REFUSED, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedTargets(): array
    {
        return [
            'loopback' => ['http://'.TestNetwork::LOOPBACK.'/'],
            'metadata' => ['http://'.TestNetwork::METADATA.'/latest/meta-data'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'mapped loopback' => ['http://[::ffff:'.TestNetwork::LOOPBACK.']/'],
            'mapped metadata' => ['http://[::ffff:'.TestNetwork::METADATA.']/'],
            'unspecified' => ['http://'.TestNetwork::ANY.'/'],
            'rfc1918 10/8' => ['http://'.TestNetwork::private(1).':8006/'],
            'rfc1918 192.168/16' => ['http://'.TestNetwork::private2(5).'/'],
            'docker bridge' => ['http://'.TestNetwork::container(5).':5000/'],
            'carrier-grade nat' => ['http://'.TestNetwork::CGNAT.'/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            "the engine's own" => ['http://'.self::engineAddress().'/'],
            'a name for loopback' => ['https://localhost/wp-admin'],
            'a name for metadata' => ['https://metadata.example/'],
            'a docker service name' => ['http://sites-db:3306/'],
            'a name for a lan address' => ['http://'.TestNetwork::private(1).'.nip.io:8006/'],
            'a public and an internal answer' => ['https://mixed.example/'],
        ];
    }

    public function test_private_addresses_are_allowed_only_when_the_operator_allows_them(): void
    {
        OutboundUrl::setPolicyForTests(true, null);

        $this->assertSame([TestNetwork::private(1)], SourceFetchGuard::assertAllowed('http://'.TestNetwork::private(1).'.nip.io/', $this->policy(), $this->dns()));
        $this->assertSame(['fd00::1'], SourceFetchGuard::assertAllowed('http://[fd00::1]/', $this->policy(), $this->dns()));
        foreach (['http://'.TestNetwork::LOOPBACK.'/', 'http://'.TestNetwork::METADATA.'/', 'http://'.TestNetwork::CGNAT.'/', 'http://'.self::engineAddress().'/'] as $url) {
            $this->assertRefused($url);
        }
    }

    public function test_an_allow_listed_name_may_lead_to_a_private_address_and_nothing_else_does(): void
    {
        OutboundUrl::setPolicyForTests(false, [TestNetwork::private(1).'.nip.io']);

        $this->assertSame([TestNetwork::private(1)], SourceFetchGuard::assertAllowed('http://'.TestNetwork::private(1).'.nip.io/', $this->policy(), $this->dns()));
        $this->assertRefused('http://'.TestNetwork::private(1).'/');
        $this->assertRefused('http://sites-db:3306/');
    }

    public function test_redirect_to_loopback_stays_absolute_for_the_next_check(): void
    {
        $next = SourceFetchGuard::resolveRedirect(
            'https://example.com/wp-login.php',
            'http://'.TestNetwork::METADATA.'/latest/meta-data',
        );

        $this->assertSame('http://'.TestNetwork::METADATA.'/latest/meta-data', $next);
        $this->assertRefused($next);
    }

    public function test_relative_redirect_stays_on_the_same_origin(): void
    {
        $this->assertSame(
            'https://example.com/wp-admin/',
            SourceFetchGuard::resolveRedirect('https://example.com/wp-login.php', '/wp-admin/'),
        );
        $this->assertSame(
            'https://example.com/blog/wp-admin/',
            SourceFetchGuard::resolveRedirect('https://example.com/blog/wp-login.php', 'wp-admin/'),
        );
    }

    private static function engineAddress(): string
    {
        return TestNetwork::routable(40);
    }

    private function assertRefused(string $url): void
    {
        try {
            SourceFetchGuard::assertAllowed($url, $this->policy(), $this->dns());
            $this->fail("{$url} must be refused.");
        } catch (RuntimeException $e) {
            $this->assertSame(SourceFetchGuard::REFUSED, $e->getMessage());
        }
    }

    private function policy(): SourceIpPolicy
    {
        return new SourceIpPolicy(new class extends EngineAddresses
        {
            public function ranges(): array
            {
                return [TestNetwork::routable(40)];
            }
        });
    }

    /**
     * @return callable(string): list<string>
     */
    private function dns(): callable
    {
        return static fn (string $host): array => match ($host) {
            'example.com' => [TestNetwork::routable(34)],
            'localhost' => [TestNetwork::LOOPBACK],
            'metadata.example' => [TestNetwork::METADATA],
            'sites-db' => [TestNetwork::container2(2)],
            TestNetwork::private(1).'.nip.io' => [TestNetwork::private(1)],
            'mixed.example' => [TestNetwork::routable(34), TestNetwork::private(1)],
            default => [],
        };
    }
}
