<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPin;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestNetwork;
use Tests\Support\WordpressMigrationSourceIpFixtures;

/**
 * Every source request is pinned with CURLOPT_RESOLVE: to `source_ip` for the
 * source host, otherwise to the address the fetch guard checked, so curl
 * never resolves the host again. A `source_ip`, and every address a request
 * goes to, must be a public address that is not the Engine's own.
 */
class WordpressMigrationSourceIpTest extends TestCase
{
    use WordpressMigrationSourceIpFixtures;

    public function test_the_source_host_and_its_www_twin_are_pinned_to_source_ip(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', TestNetwork::routable(34)), $this->dns(), $this->policy());

        $this->assertSame(
            ['-example.com:80', 'example.com:80:'.TestNetwork::routable(34), '-example.com:443', 'example.com:443:'.TestNetwork::routable(34)],
            $client->resolveEntries('https://example.com/wp-login.php'),
        );
        $this->assertSame(
            ['-www.example.com:80', 'www.example.com:80:'.TestNetwork::routable(34), '-www.example.com:443', 'www.example.com:443:'.TestNetwork::routable(34)],
            $client->resolveEntries('http://www.example.com/'),
        );
    }

    public function test_without_source_ip_the_checked_address_is_pinned(): void
    {
        $client = new CurlSourceHttpClient(null, $this->dns(), $this->policy());

        $this->assertSame(
            ['-example.com:80', 'example.com:80:'.TestNetwork::routable(20), '-example.com:443', 'example.com:443:'.TestNetwork::routable(20), '-example.com:8443', 'example.com:8443:'.TestNetwork::routable(20)],
            $client->resolveEntries('https://example.com:8443/'),
        );
    }

    public function test_another_host_is_checked_and_pinned_on_its_own_dns(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', TestNetwork::routable(34)), $this->dns(), $this->policy());

        $this->assertContains('cdn.example.net:443:'.TestNetwork::routable(69), $client->resolveEntries('https://cdn.example.net/x'));
    }

    public function test_a_host_resolving_to_loopback_is_refused_before_any_connection(): void
    {
        $client = new CurlSourceHttpClient(null, static fn (): array => [TestNetwork::LOOPBACK], $this->policy());

        $this->expectException(RuntimeException::class);
        $client->resolveEntries('https://rebind.example/');
    }

    public function test_a_redirect_to_a_docker_or_lan_address_is_refused_before_any_connection(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', TestNetwork::routable(34)), $this->dns(), $this->policy());

        foreach (['http://sites-db:3306/', 'http://'.TestNetwork::container(5).':5000/', 'http://'.TestNetwork::private(1).':8006/', 'http://'.TestNetwork::routable(40).'/'] as $url) {
            try {
                $client->resolveEntries($url);
                $this->fail("{$url} must be refused.");
            } catch (RuntimeException $e) {
                $this->assertSame(SourceFetchGuard::REFUSED, $e->getMessage());
            }
        }
    }

    public function test_a_source_ip_pin_is_checked_again_before_it_is_used(): void
    {
        $client = new CurlSourceHttpClient(new SourceIpPin('example.com', TestNetwork::container(5)), $this->dns(), $this->policy());

        $this->expectExceptionMessage(SourceFetchGuard::REFUSED);
        $client->resolveEntries('https://example.com/');
    }

    public function test_an_ip_url_needs_no_pin_and_an_ipv6_pin_is_bracketed(): void
    {
        $this->assertSame([], (new CurlSourceHttpClient(null, $this->dns(), $this->policy()))->resolveEntries('http://'.TestNetwork::routable(34).'/'));
        $this->assertContains(
            'example.com:443:[2606:2800:220:1:248:1893:25c8:1946]',
            (new CurlSourceHttpClient(new SourceIpPin('example.com', '2606:2800:220:1:248:1893:25c8:1946'), null, $this->policy()))->resolveEntries('https://example.com/'),
        );
    }

    /**
     * @return callable(string): list<string>
     */
    private function dns(): callable
    {
        return static fn (string $host): array => match ($host) {
            'example.com', 'www.example.com' => [TestNetwork::routable(20), TestNetwork::routable(21)],
            'cdn.example.net' => [TestNetwork::routable(69)],
            'sites-db' => [TestNetwork::container2(2)],
            default => [],
        };
    }
}
