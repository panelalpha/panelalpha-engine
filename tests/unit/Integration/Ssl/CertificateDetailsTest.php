<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\CertificateDetails;
use PHPUnit\Framework\TestCase;

class CertificateDetailsTest extends TestCase
{
    public function test_it_names_the_signer_and_the_key(): void
    {
        $authority = TestCertificates::authority('Pretend Root');
        $details = CertificateDetails::of(TestCertificates::signedBy(['shop.example.com'], $authority));

        $this->assertNotNull($details);
        $this->assertSame('shop.example.com', $details['subject']['common_name']);
        $this->assertSame('Pretend Root', $details['signer']['common_name']);
        $this->assertSame('Pretend Root', $details['signer']['organization']);
        $this->assertSame('RSA', $details['key']['type']);
        $this->assertSame(2048, $details['key']['bits']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $details['expires_at']);
    }
}
