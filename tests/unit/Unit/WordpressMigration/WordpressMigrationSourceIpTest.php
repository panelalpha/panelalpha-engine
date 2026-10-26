<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPin;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every source request is pinned with CURLOPT_RESOLVE: to `source_ip` for the
 * source host, otherwise to the address the fetch guard checked, so curl
 * never resolves the host again. A `source_ip` must be a public address that
 * is not the Engine's own.
 */
class WordpressMigrationSourceIpTest extends TestCase
{
    public function test_the_source_host_and_its_www_twin_are_pinned_to_source_ip(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', '93.184.216.34'), $this->dns());

        $this->assertSame(
            ['-example.com:80', 'example.com:80:93.184.216.34', '-example.com:443', 'example.com:443:93.184.216.34'],
            $client->resolveEntries('https://example.com/wp-login.php'),
        );
        $this->assertSame(
            ['-www.example.com:80', 'www.example.com:80:93.184.216.34', '-www.example.com:443', 'www.example.com:443:93.184.216.34'],
            $client->resolveEntries('http://www.example.com/'),
        );
    }

    public function test_without_source_ip_the_checked_address_is_pinned(): void
    {
        $client = new CurlSourceHttpClient(null, $this->dns());

        $this->assertSame(
            ['-example.com:80', 'example.com:80:198.51.100.20', '-example.com:443', 'example.com:443:198.51.100.20', '-example.com:8443', 'example.com:8443:198.51.100.20'],
            $client->resolveEntries('https://example.com:8443/'),
        );
    }

    public function test_another_host_is_checked_and_pinned_on_its_own_dns(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', '93.184.216.34'), $this->dns());

        $this->assertContains('cdn.example.net:443:198.51.100.30', $client->resolveEntries('https://cdn.example.net/x'));
    }

    public function test_a_host_resolving_to_loopback_is_refused_before_any_connection(): void
    {
        $client = new CurlSourceHttpClient(null, static fn (): array => ['127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $client->resolveEntries('https://rebind.example/');
    }

    public function test_an_ip_url_needs_no_pin_and_an_ipv6_pin_is_bracketed(): void
    {
        $this->assertSame([], (new CurlSourceHttpClient(null, $this->dns()))->resolveEntries('http://93.184.216.34/'));
        $this->assertContains(
            'example.com:443:[2606:2800:220:1:248:1893:25c8:1946]',
            (new CurlSourceHttpClient(new SourceIpPin('example.com', '2606:2800:220:1:248:1893:25c8:1946')))->resolveEntries('https://example.com/'),
        );
    }

    public function test_access_pins_the_host_of_the_source_url(): void
    {
        $access = new SourceAccess(url: 'https://Example.com/blog', method: WordpressMigration::METHOD_WP_ADMIN, sourceIp: '93.184.216.34');

        $this->assertSame('example.com', $access->pin()?->host);
        $this->assertNull((new SourceAccess(url: 'https://example.com'))->pin());
    }

    #[DataProvider('refusedAddresses')]
    public function test_source_ip_refuses_non_public_addresses(string $ip): void
    {
        $this->assertNotNull($this->policy()->refusal($ip));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'link-local / metadata' => ['169.254.169.254'],
            'private' => ['10.1.2.3'],
            'docker bridge' => ['172.17.0.1'],
            'private lan' => ['192.168.1.10'],
            'carrier-grade nat' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'ipv6 loopback' => ['::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 unique local' => ['fd00::1'],
            'mapped loopback' => ['::ffff:127.0.0.1'],
            "engine's own" => ['93.184.216.40'],
            "engine's subnet" => ['93.184.217.9'],
            'not an address' => ['example.com'],
        ];
    }

    public function test_source_ip_accepts_a_public_address(): void
    {
        $this->assertNull($this->policy()->refusal('93.184.216.34'));
        $this->assertNull($this->policy()->refusal('2606:2800:220:1:248:1893:25c8:1946'));
    }

    private function policy(): SourceIpPolicy
    {
        return new SourceIpPolicy(new class extends EngineAddresses {
            public function ranges(): array
            {
                return ['93.184.216.40', '93.184.217.0/24'];
            }
        });
    }

    /**
     * @return callable(string): list<string>
     */
    private function dns(): callable
    {
        return static fn (string $host): array => match ($host) {
            'example.com', 'www.example.com' => ['198.51.100.20', '198.51.100.21'],
            'cdn.example.net' => ['198.51.100.30'],
            default => [],
        };
    }
}
