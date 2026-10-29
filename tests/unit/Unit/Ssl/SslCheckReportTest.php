<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\SslCheckReport;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** What `pae ssl:check` prints for an SslCheck result. */
class SslCheckReportTest extends TestCase
{
    public function test_a_trusted_site_served_from_here(): void
    {
        $this->assertSame([
            'shop.example.com: trusted',
            "  signer:  Let's Encrypt, R11, US",
            '  expires: 2026-12-01T10:00:00Z (35 days)',
            '  dns:     '.TestNetwork::public(10).' (points here)',
            '  public:  '.TestNetwork::public(10).": trusted, issuer Let's Encrypt R11, expires 2026-12-01T10:00:00Z (35 days)",
            '  engine:  '.TestNetwork::private(5).": trusted, issuer Let's Encrypt R11, expires 2026-12-01T10:00:00Z (35 days), the installed certificate",
        ], SslCheckReport::lines(self::trusted()));
    }

    public function test_a_name_that_does_not_resolve_and_an_engine_that_does_not_answer(): void
    {
        $this->assertSame([
            'gone.example.com: not_resolving',
            '  signer:  -',
            '  expires: -',
            '  dns:     does not resolve',
            '  public:  not checked',
            '  engine:  '.TestNetwork::private(5).': connection refused',
        ], SslCheckReport::lines([
            'domain' => 'gone.example.com',
            'status' => 'not_resolving',
            'expires_at' => null,
            'days_remaining' => null,
            'signer' => null,
            'dns' => ['addresses' => [], 'expected' => [TestNetwork::public(10)], 'points_here' => false],
            'public' => null,
            'engine' => ['address' => TestNetwork::private(5), 'error' => 'connection refused', 'serves_installed' => null],
        ]));
    }

    public function test_dns_elsewhere_and_a_renewal_the_webserver_never_loaded(): void
    {
        $result = self::trusted();
        $result['signer'] = ['common_name' => null, 'organization' => null, 'country' => null];
        $result['dns'] = ['addresses' => [TestNetwork::public2(7)], 'expected' => [], 'points_here' => false];
        $result['public']['certificate'] = ['status' => 'self_signed', 'issuer' => null];
        $result['engine']['serves_installed'] = false;

        $lines = SslCheckReport::lines($result);

        $this->assertSame('  signer:  unknown', $lines[1]);
        $this->assertSame('  dns:     '.TestNetwork::public2(7).' (expected none)', $lines[3]);
        $this->assertSame('  public:  '.TestNetwork::public(10).': self_signed, issuer unknown', $lines[4]);
        $this->assertStringEndsWith(', NOT the installed certificate (reload the webserver?)', $lines[5]);
    }

    public function test_json_is_the_whole_result_pretty_printed(): void
    {
        $this->assertSame(
            json_encode(self::trusted(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            SslCheckReport::json(self::trusted())
        );
    }

    /** @return array<string, mixed> */
    private static function trusted(): array
    {
        $certificate = [
            'status' => 'trusted',
            'issuer' => "Let's Encrypt R11",
            'expires_at' => '2026-12-01T10:00:00Z',
            'days_remaining' => 35,
        ];

        return [
            'domain' => 'shop.example.com',
            'status' => 'trusted',
            'expires_at' => '2026-12-01T10:00:00Z',
            'days_remaining' => 35,
            'signer' => ['common_name' => 'R11', 'organization' => "Let's Encrypt", 'country' => 'US'],
            'dns' => ['addresses' => [TestNetwork::public(10)], 'expected' => [TestNetwork::public(10)], 'points_here' => true],
            'public' => ['address' => TestNetwork::public(10), 'certificate' => $certificate],
            'engine' => ['address' => TestNetwork::private(5), 'certificate' => $certificate, 'serves_installed' => true],
        ];
    }
}
