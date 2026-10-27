<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A source URL must lead to a public address that is not the Engine's own,
 * and every refused address gets the same answer. Private ranges are allowed
 * only by the operator (EGRESS_ALLOW_PRIVATE, or EGRESS_ALLOW_HOSTS for a name).
 */
class WordpressMigrationFetchGuardTest extends TestCase
{
    private const ENGINE_ADDRESS = '93.184.216.40';

    protected function tearDown(): void
    {
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    public function test_https_and_public_hosts_are_allowed(): void
    {
        $this->assertSame(['93.184.216.34'], SourceFetchGuard::assertAllowed('https://example.com/blog', $this->policy(), $this->dns()));
        $this->assertSame(['151.101.1.69'], SourceFetchGuard::assertAllowed('http://151.101.1.69:8080/wp', $this->policy(), $this->dns()));
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
            'loopback' => ['http://127.0.0.1/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'mapped metadata' => ['http://[::ffff:169.254.169.254]/'],
            'unspecified' => ['http://0.0.0.0/'],
            'rfc1918 10/8' => ['http://10.0.0.1:8006/'],
            'rfc1918 192.168/16' => ['http://192.168.0.5/'],
            'docker bridge' => ['http://172.18.0.5:5000/'],
            'carrier-grade nat' => ['http://100.64.0.1/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            "the engine's own" => ['http://'.self::ENGINE_ADDRESS.'/'],
            'a name for loopback' => ['https://localhost/wp-admin'],
            'a name for metadata' => ['https://metadata.example/'],
            'a docker service name' => ['http://sites-db:3306/'],
            'a name for a lan address' => ['http://10.0.0.1.nip.io:8006/'],
            'a public and an internal answer' => ['https://mixed.example/'],
        ];
    }

    public function test_private_addresses_are_allowed_only_when_the_operator_allows_them(): void
    {
        OutboundUrl::setPolicyForTests(true, null);

        $this->assertSame(['10.0.0.1'], SourceFetchGuard::assertAllowed('http://10.0.0.1.nip.io/', $this->policy(), $this->dns()));
        $this->assertSame(['fd00::1'], SourceFetchGuard::assertAllowed('http://[fd00::1]/', $this->policy(), $this->dns()));
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://'.self::ENGINE_ADDRESS.'/'] as $url) {
            $this->assertRefused($url);
        }
    }

    public function test_an_allow_listed_name_may_lead_to_a_private_address_and_nothing_else_does(): void
    {
        OutboundUrl::setPolicyForTests(false, ['10.0.0.1.nip.io']);

        $this->assertSame(['10.0.0.1'], SourceFetchGuard::assertAllowed('http://10.0.0.1.nip.io/', $this->policy(), $this->dns()));
        $this->assertRefused('http://10.0.0.1/');
        $this->assertRefused('http://sites-db:3306/');
    }

    public function test_redirect_to_loopback_stays_absolute_for_the_next_check(): void
    {
        $next = SourceFetchGuard::resolveRedirect(
            'https://example.com/wp-login.php',
            'http://169.254.169.254/latest/meta-data',
        );

        $this->assertSame('http://169.254.169.254/latest/meta-data', $next);
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
                return ['93.184.216.40'];
            }
        });
    }

    /**
     * @return callable(string): list<string>
     */
    private function dns(): callable
    {
        return static fn (string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            'localhost' => ['127.0.0.1'],
            'metadata.example' => ['169.254.169.254'],
            'sites-db' => ['172.20.0.2'],
            '10.0.0.1.nip.io' => ['10.0.0.1'],
            'mixed.example' => ['93.184.216.34', '10.0.0.1'],
            default => [],
        };
    }
}
