<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateDetails;
use PHPUnit\Framework\TestCase;

class CertificateDetailsTest extends TestCase
{
    public function test_an_ec_key_names_its_curve(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'ec.example.com'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']), $pem);

        $details = CertificateDetails::of($pem);

        $this->assertSame(['type' => 'EC', 'bits' => 256, 'curve' => 'prime256v1'], $details['key'] ?? null);
        $this->assertSame('ecdsa-with-SHA256', $details['signature_algorithm'] ?? null);
    }

    public function test_something_that_is_not_a_certificate_has_no_details(): void
    {
        $this->assertNull(CertificateDetails::of('not a pem'));
    }
}
