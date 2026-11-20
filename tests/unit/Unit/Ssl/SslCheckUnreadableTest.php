<?php

namespace Tests\Unit\Ssl;

use App\Lib\Http\OutboundUrl;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\SslCheck;
use App\Lib\Ssl\TlsHandshake;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** A leaf that does not parse as a certificate is reported unreadable, with whatever fingerprint it has. */
class SslCheckUnreadableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setPolicyForTests(false, []);
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::public(45)]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    public function test_an_unparseable_leaf_is_unreadable(): void
    {
        $handshake = new class extends TlsHandshake
        {
            public function presented(string $address, string $serverName, int $port = 443): array
            {
                return ['leaf' => "-----BEGIN CERTIFICATE-----\nnot base64\n-----END CERTIFICATE-----\n", 'intermediates' => [], 'error' => null];
            }
        };

        $result = (new SslCheck($handshake, 1_700_000_000))->check('shop.example.com', [TestNetwork::public(45)], null, null);

        $this->assertSame(CertificateStatus::UNREADABLE, $result['status']);
        $this->assertSame(['status' => CertificateStatus::UNREADABLE, 'fingerprint_sha256' => null], $result['public']['certificate']);
        $this->assertTrue($result['public']['answered']);
        $this->assertNull($result['engine']);
        $this->assertSame('2023-11-14T22:13:20Z', $result['checked_at']);
    }
}
