<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\TrustStore;
use PHPUnit\Framework\TestCase;

/**
 * The difference between "signed by somebody" and "signed by somebody anyone
 * trusts", which `status: trusted` used to miss.
 */
class TrustStoreTest extends TestCase
{
    private const IN_DATE = ['not_before' => 1, 'not_after' => 4102444800];

    public function test_a_checked_failure_is_not_trusted(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => "(STAGING) Pretend Pear X1",
            'domains' => ['shop.example.com'],
            'chain_trusted' => false,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::UNTRUSTED_ISSUER, $status['status']);
        $this->assertFalse($status['self_signed'], 'it was signed by an authority, just not one we trust');
        $this->assertFalse($status['chain_trusted']);
    }

    /**
     * "Could not check" must keep reading as it always did: a host with no CA
     * bundle would otherwise report every real certificate as suspect.
     */
    public function test_an_unchecked_certificate_still_reads_as_trusted(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => 'R11',
            'domains' => ['shop.example.com'],
            'chain_trusted' => null,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::TRUSTED, $status['status']);
        $this->assertNull($status['chain_trusted']);
    }

    public function test_self_signed_still_wins_over_the_chain_verdict(): void
    {
        $status = CertificateStatus::of(self::IN_DATE + [
            'common_name' => 'shop.example.com',
            'issuer_common_name' => 'shop.example.com',
            'domains' => ['shop.example.com'],
            'chain_trusted' => false,
        ], 'shop.example.com');

        $this->assertSame(CertificateStatus::SELF_SIGNED, $status['status']);
    }

    public function test_nothing_is_not_a_certificate(): void
    {
        $this->assertNull(TrustStore::verifies(''));
        $this->assertNull(TrustStore::verifies('not a pem'));
    }
}
