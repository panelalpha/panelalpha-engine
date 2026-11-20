<?php

namespace Tests\Integration\Ssl;

use App\Lib\Ssl\AcmeIssuer;
use App\Lib\Ssl\IssuableDomain;
use App\Models\Domain;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** A name no authority will issue for is refused before the ACME client is built, and nothing is installed. */
class AcmeIssuerRefusalTest extends TestCase
{
    /** @return array<string, array{string, bool, string}> */
    public static function refusedNames(): array
    {
        return [
            'a reserved name' => ['shop.example.test', true, 'shop.example.test is not a public hostname a certificate authority will issue for'],
            'a shared zone while not allowed' => [
                'shop.nip.io',
                false,
                'shop.nip.io is under nip.io, a zone every engine in the fleet issues under and shares one 50-certificate '
                .'weekly limit on. To obtain one anyway, turn on the '
                .'ssl_shared_zone_issuance setting (the shared_zone_issuance field of the SSL config), or give the '
                .'project a domain you own',
            ],
        ];
    }

    #[DataProvider('refusedNames')]
    public function test_a_name_no_authority_issues_for_is_refused_before_anything_happens(string $name, bool $sharedZoneAllowed, string $reason): void
    {
        $domain = Mockery::mock(IssuableDomain::class);
        $domain->shouldReceive('model')->andReturn(new Domain(['domain' => $name]));
        $domain->shouldNotReceive('putCertificate', 'publishCertificateToHostWebserver', 'generateSelfSignedCertificate');
        $issuer = new AcmeIssuer('http://acme.invalid/directory', null, null, null, $sharedZoneAllowed);

        try {
            $issuer->issue($domain);
            $this->fail('the name must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($reason, $e->getMessage());
            $this->assertSame($reason, $issuer->ineligibleReason($name));
        }
    }
}
