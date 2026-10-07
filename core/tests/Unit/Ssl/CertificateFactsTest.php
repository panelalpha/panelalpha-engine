<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use PHPUnit\Framework\TestCase;

/**
 * Reading a real certificate, against two real certificates.
 *
 * Both are generated, self-signed and valid until 2036, and they differ in
 * exactly the thing that matters here: one carries a wildcard in its
 * subjectAltName and one does not. That difference is what decides whether
 * every project on a host can serve the engine's certificate or has to have
 * one of its own, so it is worth testing against openssl's actual output
 * rather than a hand-written array.
 */
class CertificateFactsTest extends TestCase
{
    private const WILD_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDozCCAougAwIBAgIUIbuZbS2pURVNrJh0RVu+PYdN5cowDQYJKoZIhvcNAQEL
BQAwSzELMAkGA1UEBhMCVVMxEzARBgNVBAoMClBhbmVsQWxwaGExJzAlBgNVBAMM
HjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVjdDAeFw0yNjEwMDcxMDU2NTVa
Fw0zNjEwMDQxMDU2NTVaMEsxCzAJBgNVBAYTAlVTMRMwEQYDVQQKDApQYW5lbEFs
cGhhMScwJQYDVQQDDB4yMDMtMC0xMTMtNDUucGFuZWxhbHBoYS5kaXJlY3QwggEi
MA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCVCIpmjjimSRdTyrDH9NjsuTg/
x82sWuub/7eZINDtADwrIkb8jCYdNi9U6YBSZTP650Pu1YjnVD98OWUBfZ8qMnMF
aEgzb0XXw5BvQL5ydLLCSsrvhy1OvQlJFwKDs5QMgHFGnj96HJ14rGb+Sseq8h+k
Lr9RyvxwJu1Sixx/IFbtYEXLhOgQVdQsw+Qxd0k9QQ7fjViiX5zwF7edyG8/euc0
EdiItnsIqhpPeLjN4LzXIXT7hSHorKymForvsXBNhS8FFG3MW9s3xa9zjhgAazbc
xFkE1cotYSnhZvw0lxdpcj619FDLcbmRmv7qTnSIYgB0fJs4lTsjUa3rfLEjAgMB
AAGjfzB9MB0GA1UdDgQWBBQD0cUyHalcWDL0GzvM+yZpQinEyDAPBgNVHRMBAf8E
BTADAQH/MEsGA1UdEQREMEKCHjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVj
dIIgKi4yMDMtMC0xMTMtNDUucGFuZWxhbHBoYS5kaXJlY3QwDQYJKoZIhvcNAQEL
BQADggEBABUhcmuK5P34tOsp+9yDdJ61/TT+lWM/EOXJEfksIRMJHGZO0n6AN4hy
v/hqOTcAIkfB9ACaQ3lRdU+eyo9o3nGPwGsHYBQz8RTcGvOuudWS4b7W14p2MUSM
PD07wlUFx+ze+LDSrsObrXUIyiNbBZb62Xq8c8uznlNcNK0tUkNQ5N8/YZIfdluG
aZA6e19BRJST/JFAtRvxJ0TJffhzcx9Ay+1cO8vmDyxKMU5VQppfv4OdlxCq2a/L
GjnVkZHG6J34K+uneszeuYAXpfQCoGmBX2dbXkIIzLoPOWmAV0Irf4eFKOc3WkK8
vJk+/O9KvY9EgAdwWzmd7gGfhU2ssXo=
-----END CERTIFICATE-----
PEM;

    private const SINGLE_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDgTCCAmmgAwIBAgIUcWNjULtRoZAfKjybHd93nk4ikJowDQYJKoZIhvcNAQEL
BQAwSzELMAkGA1UEBhMCVVMxEzARBgNVBAoMClBhbmVsQWxwaGExJzAlBgNVBAMM
HjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVjdDAeFw0yNjEwMDcxMDU2NTVa
Fw0zNjEwMDQxMDU2NTVaMEsxCzAJBgNVBAYTAlVTMRMwEQYDVQQKDApQYW5lbEFs
cGhhMScwJQYDVQQDDB4yMDMtMC0xMTMtNDUucGFuZWxhbHBoYS5kaXJlY3QwggEi
MA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDUHRXW1Uc6itPgE6/ZeF0P1WiM
4xEP/TFHWfQeXlODvyV1fet/h4VRJuKT4i+49FWtGAdts1bAiDQvS3rU9wc/aSYh
Wah4wNSs8yZEzmBwDVseJOIyshQvl3jHW8IUUhXax9SOu3g44LlteJWCXMGlO10P
k5171tTXfbaqtwCe/cEwkiiqppAyKps4xf4raL8dr6p78mTDrDjnYSAfX9+NqlaF
YcZdm/XXX6j0DDBEps+gwf5hfB7DcQTbsUGl0xN6G9WFADJyLsfWXMVxbHtThAsd
vE+GO/pWQc58yDUa8Z8IWlaWAZGQnaNgCa65N7ygX7tIV9xyXGS3jrQPFCAxAgMB
AAGjXTBbMB0GA1UdDgQWBBRjSUeICsAEe7nyiKpPEcvr5Hq1ZDAPBgNVHRMBAf8E
BTADAQH/MCkGA1UdEQQiMCCCHjIwMy0wLTExMy00NS5wYW5lbGFscGhhLmRpcmVj
dDANBgkqhkiG9w0BAQsFAAOCAQEAgwqzSH+AsUvUMHG7POouaSXaq9f08V5fjuJH
kDurBjOpIyDfeaArlCfMMn8LnEuWsvzzh+mfx13jkIgPF40aLTVBxx5N88S/VgKf
xmoef76VS/G2Qc1M8Is2VQ8HhRUxbbaVAy+YG9bQMRCwzgTmh4IZPaQIJlnLSkfB
fkAVaChsE+5SxBEDGlbqB+NihBX7KUJE+uqhsJIxah7NTHgRrl5qoF9OSxJGTjT4
cZpDXjutZoCp4T7WsjYwIw1m/O1DrhujImm4H1uJZucGt7fOemk0sN4cscTwQuVl
ogQaptadgRZB38mEkeTkEL1b3wPb5NtofaUPwss/jCbBOSK8lA==
-----END CERTIFICATE-----
PEM;


    public function test_a_wildcard_certificate_reports_both_of_its_names(): void
    {
        $facts = CertificateFacts::fromPem(self::WILD_CERT);

        $this->assertNotNull($facts);
        $this->assertSame('203-0-113-45.panelalpha.direct', $facts['common_name']);
        $this->assertSame('PanelAlpha', $facts['issuer_name']);
        $this->assertSame(
            ['203-0-113-45.panelalpha.direct', '*.203-0-113-45.panelalpha.direct'],
            $facts['domains'],
            'the subjectAltName list is the certificate\'s real name list'
        );
        $this->assertIsInt($facts['not_before']);
        $this->assertIsInt($facts['not_after']);
    }

    /**
     * The whole point of the exercise: a wildcard covers every project on the
     * host, and the certificate without one covers only the engine itself.
     */
    public function test_only_the_wildcard_covers_a_project_domain(): void
    {
        $project = 'demo.203-0-113-45.panelalpha.direct';

        $wild = CertificateStatus::of(CertificateFacts::fromPem(self::WILD_CERT), $project);
        $single = CertificateStatus::of(CertificateFacts::fromPem(self::SINGLE_CERT), $project);

        $this->assertTrue($wild['covers_domain']);
        $this->assertFalse($single['covers_domain']);
        $this->assertSame(CertificateStatus::DOMAIN_MISMATCH, $single['status']);
    }

    public function test_both_cover_the_engine_name_itself(): void
    {
        $engine = '203-0-113-45.panelalpha.direct';

        foreach ([self::WILD_CERT, self::SINGLE_CERT] as $pem) {
            $status = CertificateStatus::of(CertificateFacts::fromPem($pem), $engine);
            $this->assertTrue($status['covers_domain']);
            $this->assertSame(CertificateStatus::SELF_SIGNED, $status['status'], 'both fixtures are self-signed');
        }
    }

    /**
     * A wildcard is one label deep, which is a constraint on how project
     * domains may ever be named.
     */
    public function test_a_wildcard_does_not_reach_two_labels_deep(): void
    {
        $status = CertificateStatus::of(
            CertificateFacts::fromPem(self::WILD_CERT),
            'a.b.203-0-113-45.panelalpha.direct'
        );

        $this->assertFalse($status['covers_domain']);
    }

    public function test_something_that_is_not_a_certificate_reads_as_nothing(): void
    {
        $this->assertNull(CertificateFacts::fromPem(''));
        $this->assertNull(CertificateFacts::fromPem('   '));
        $this->assertNull(CertificateFacts::fromPem('-----BEGIN CERTIFICATE-----\nnot base64\n-----END CERTIFICATE-----'));
        $this->assertNull(CertificateFacts::fromPem(self::WILD_CERT . 'trailing junk'));
    }
}
