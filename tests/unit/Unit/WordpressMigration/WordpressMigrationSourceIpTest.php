<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    public function test_access_pins_the_host_of_the_source_url(): void
    {
        $access = new SourceAccess(url: 'https://Example.com/blog', method: WordpressMigration::METHOD_WP_ADMIN, sourceIp: TestNetwork::routable(34));

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
            'loopback' => [TestNetwork::LOOPBACK],
            'link-local / metadata' => [TestNetwork::METADATA],
            'private' => [TestNetwork::private(3)],
            'docker bridge' => [TestNetwork::DOCKER_BRIDGE_GATEWAY],
            'private lan' => [TestNetwork::private2(10)],
            'carrier-grade nat' => [TestNetwork::CGNAT],
            'unspecified' => [TestNetwork::ANY],
            'ipv6 loopback' => ['::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 unique local' => ['fd00::1'],
            'mapped loopback' => ['::ffff:'.TestNetwork::LOOPBACK],
            "engine's own" => [TestNetwork::routable(40)],
            "engine's subnet" => [TestNetwork::add(TestNetwork::routable(9), 256)],
            'not an address' => ['example.com'],
        ];
    }

    public function test_source_ip_accepts_a_public_address(): void
    {
        $this->assertNull($this->policy()->refusal(TestNetwork::routable(34)));
        $this->assertNull($this->policy()->refusal('2606:2800:220:1:248:1893:25c8:1946'));
    }

    public function test_a_private_source_ip_is_accepted_only_when_the_operator_allows_private_addresses(): void
    {
        OutboundUrl::setPolicyForTests(true, null);
        try {
            $this->assertNull($this->policy()->refusal(TestNetwork::private(3)));
            $this->assertNull($this->policy()->refusal('fd00::1'));
            $this->assertNotNull($this->policy()->refusal(TestNetwork::LOOPBACK));
            $this->assertNotNull($this->policy()->refusal(TestNetwork::CGNAT));
            $this->assertNotNull($this->policy()->refusal(TestNetwork::routable(40)));
        } finally {
            OutboundUrl::setPolicyForTests(null, null);
        }
    }
}
