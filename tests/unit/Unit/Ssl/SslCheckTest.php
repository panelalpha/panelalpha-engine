<?php

namespace Tests\Unit\Ssl;

use App\Lib\Http\OutboundUrl;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\SslCheck;
use App\Lib\Ssl\TlsHandshake;
use PHPUnit\Framework\TestCase;

/**
 * The verdict comes from what a handshake with the domain returned, never from
 * the certificate on disk.
 */
class SslCheckTest extends TestCase
{
    private const DOMAIN = 'shop.example.com';

    private const HOST_IP = '203.0.113.45';

    private const BIND_IP = '10.0.0.5';

    /** @var list<array{address: string, name: string}> */
    private array $dialled = [];

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setPolicyForTests(false, []);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    public function test_a_name_with_no_records_does_not_resolve_and_the_engine_side_is_still_checked(): void
    {
        $cert = TestCertificates::selfSigned([self::DOMAIN]);
        $result = $this->check([], [self::BIND_IP => $cert['cert']], $cert['cert']);

        $this->assertSame(SslCheck::NOT_RESOLVING, $result['status']);
        $this->assertNull($result['public']);
        $this->assertFalse($result['dns']['points_here']);
        $this->assertSame(CertificateStatus::SELF_SIGNED, $result['engine']['certificate']['status']);
        $this->assertSame([['address' => self::BIND_IP, 'name' => self::DOMAIN]], $this->dialled);
    }

    public function test_the_status_is_what_the_resolved_address_presents(): void
    {
        $cert = TestCertificates::selfSigned([self::DOMAIN, 'www.'.self::DOMAIN]);
        $result = $this->check([self::HOST_IP], [self::HOST_IP => $cert['cert'], self::BIND_IP => $cert['cert']], $cert['cert']);

        $this->assertSame(CertificateStatus::SELF_SIGNED, $result['status']);
        $this->assertTrue($result['dns']['points_here']);
        $this->assertTrue($result['public']['answered']);
        $this->assertSame([self::DOMAIN, 'www.'.self::DOMAIN], $result['public']['certificate']['names']);
        $this->assertTrue($result['public']['certificate']['covers_domain']);
        $this->assertContains($result['public']['certificate']['days_remaining'], [29, 30]);
        $this->assertContains(['address' => self::HOST_IP, 'name' => self::DOMAIN], $this->dialled, 'the domain goes out as SNI');
    }

    public function test_the_installed_certificate_on_disk_does_not_decide_the_status(): void
    {
        $served = TestCertificates::selfSigned(['other.example.com']);
        $installed = TestCertificates::selfSigned([self::DOMAIN]);
        $result = $this->check([self::HOST_IP], [self::HOST_IP => $served['cert'], self::BIND_IP => $served['cert']], $installed['cert']);

        $this->assertSame(CertificateStatus::DOMAIN_MISMATCH, $result['status']);
        $this->assertFalse($result['engine']['serves_installed'], 'a renewal the webserver never reloaded looks like this');
    }

    public function test_the_engine_serving_the_installed_certificate_says_so(): void
    {
        $cert = TestCertificates::selfSigned([self::DOMAIN]);
        $result = $this->check([self::HOST_IP], [self::HOST_IP => $cert['cert'], self::BIND_IP => $cert['cert']], $cert['cert']);

        $this->assertTrue($result['engine']['serves_installed']);
        $this->assertSame(
            openssl_x509_fingerprint($cert['cert'], 'sha256'),
            $result['public']['certificate']['fingerprint_sha256'],
        );
    }

    public function test_dns_pointing_elsewhere_reports_what_that_server_presents(): void
    {
        $cdn = TestCertificates::signedBy([self::DOMAIN], TestCertificates::authority('Pretend CDN CA'));
        $ours = TestCertificates::selfSigned([self::DOMAIN]);
        $result = $this->check(['104.16.0.1'], ['104.16.0.1' => $cdn, self::BIND_IP => $ours['cert']], $ours['cert']);

        $this->assertFalse($result['dns']['points_here']);
        $this->assertSame('104.16.0.1', $result['public']['address']);
        $this->assertSame(CertificateStatus::UNTRUSTED_ISSUER, $result['status'], 'signed, but by an authority this host does not trust');
        $this->assertSame('Pretend CDN CA', $result['public']['certificate']['issuer']);
        $this->assertSame(CertificateStatus::SELF_SIGNED, $result['engine']['certificate']['status']);
    }

    public function test_a_private_address_that_is_not_ours_is_never_contacted(): void
    {
        $result = $this->check(['169.254.169.254'], [], null);

        $this->assertSame(SslCheck::REFUSED_ADDRESS, $result['status']);
        $this->assertTrue($result['public']['refused']);
        $this->assertNotContains('169.254.169.254', array_column($this->dialled, 'address'));
    }

    public function test_a_private_address_that_is_ours_is_contacted(): void
    {
        $cert = TestCertificates::selfSigned([self::DOMAIN]);
        $result = $this->check(['10.10.10.25'], ['10.10.10.25' => $cert['cert']], null, ['10.10.10.25']);

        $this->assertSame(CertificateStatus::SELF_SIGNED, $result['status']);
        $this->assertTrue($result['dns']['points_here']);
    }

    public function test_nothing_answering_is_unreachable_with_the_reason(): void
    {
        $result = $this->check([self::HOST_IP], [], null);

        $this->assertSame(SslCheck::UNREACHABLE, $result['status']);
        $this->assertFalse($result['public']['answered']);
        $this->assertSame('Connection refused', $result['public']['error']);
        $this->assertNull($result['engine']['serves_installed']);
    }

    public function test_an_expired_certificate_is_expired(): void
    {
        $cert = TestCertificates::selfSigned([self::DOMAIN], 1);
        $result = $this->check([self::HOST_IP], [self::HOST_IP => $cert['cert']], null, [self::HOST_IP], time() + 3 * 86400);

        $this->assertSame(CertificateStatus::EXPIRED, $result['status']);
        $this->assertLessThan(0, $result['public']['certificate']['days_remaining']);
    }

    public function test_the_expiry_and_signer_of_what_visitors_get_are_at_the_top(): void
    {
        $authority = TestCertificates::authority('Pretend Issuing CA');
        $leaf = TestCertificates::signedBy([self::DOMAIN], $authority);
        $this->resolveTo([self::HOST_IP]);
        $result = (new SslCheck($this->handshake([self::HOST_IP => $leaf], [$authority['cert']])))
            ->check(self::DOMAIN, [self::HOST_IP], null, null);

        $certificate = $result['public']['certificate'];
        $this->assertSame('Pretend Issuing CA', $result['signer']['common_name']);
        $this->assertSame('Pretend Issuing CA', $result['signer']['organization']);
        $this->assertSame($certificate['expires_at'], $result['expires_at']);
        $this->assertSame($certificate['days_remaining'], $result['days_remaining']);
        $this->assertSame('Pretend Issuing CA', $result['issuer']);
        $this->assertSame(['type' => 'RSA', 'bits' => 2048, 'curve' => null], $certificate['key']);
        $this->assertSame('sha256WithRSAEncryption', $certificate['signature_algorithm']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]+$/', (string) $certificate['serial_number']);
        $this->assertCount(1, $certificate['chain']);
        $this->assertSame('Pretend Issuing CA', $certificate['chain'][0]['subject']['common_name']);
    }

    public function test_no_certificate_means_no_expiry_or_signer(): void
    {
        $result = $this->check([], [], null);

        $this->assertNull($result['expires_at']);
        $this->assertNull($result['signer']);
        $this->assertNull($result['issuer']);
    }

    public function test_intermediates_the_server_sends_reach_the_chain_check(): void
    {
        $authority = TestCertificates::authority();
        $leaf = TestCertificates::signedBy([self::DOMAIN], $authority);
        $this->resolveTo([self::HOST_IP]);
        $handshake = $this->handshake([self::HOST_IP => $leaf], [$authority['cert']]);
        $result = (new SslCheck($handshake))->check(self::DOMAIN, [self::HOST_IP], null, null);

        $this->assertFalse($result['public']['certificate']['chain_trusted'], 'the throwaway root is in no store');
        $this->assertFalse($result['public']['certificate']['self_signed']);
    }

    /**
     * @param  list<string>  $resolved
     * @param  array<string, string>  $presented  address => leaf PEM; an address not listed refuses the connection
     * @param  ?list<string>  $expected
     * @return array<string, mixed>
     */
    private function check(array $resolved, array $presented, ?string $installed, ?array $expected = null, ?int $now = null): array
    {
        $this->resolveTo($resolved);

        return (new SslCheck($this->handshake($presented), $now))
            ->check(self::DOMAIN, $expected ?? [self::HOST_IP], self::BIND_IP, $installed);
    }

    /**
     * @param  list<string>  $addresses
     */
    private function resolveTo(array $addresses): void
    {
        OutboundUrl::setResolverForTests(static fn (string $host): array => $host === self::DOMAIN ? $addresses : []);
    }

    /**
     * @param  array<string, string>  $presented
     * @param  list<string>  $intermediates
     */
    private function handshake(array $presented, array $intermediates = []): TlsHandshake
    {
        $dialled = &$this->dialled;

        return new class($presented, $intermediates, $dialled) extends TlsHandshake
        {
            /**
             * @param  array<string, string>  $presented
             * @param  list<string>  $intermediates
             * @param  list<array{address: string, name: string}>  $dialled
             */
            public function __construct(private array $presented, private array $intermediates, private array &$dialled) {}

            public function presented(string $address, string $serverName, int $port = 443): array
            {
                $this->dialled[] = ['address' => $address, 'name' => $serverName];
                $leaf = $this->presented[$address] ?? null;

                return $leaf === null
                    ? ['leaf' => null, 'intermediates' => [], 'error' => 'Connection refused']
                    : ['leaf' => $leaf, 'intermediates' => $this->intermediates, 'error' => null];
            }
        };
    }
}
