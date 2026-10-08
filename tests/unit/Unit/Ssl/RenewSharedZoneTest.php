<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\AcmeIssuer;
use App\Lib\Ssl\ProjectCertificate;
use App\Models\Setting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Renewal follows issuance's shared-zone rule: a certificate the operator
 * issued on a shared zone on purpose is renewed, not left to expire.
 */
class RenewSharedZoneTest extends TestCase
{
    private const SHARED = 'shop.203-0-113-7.sslip.io';

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public static function modes(): array
    {
        return ['production' => [false], 'staging' => [true]];
    }

    #[DataProvider('modes')]
    public function test_a_shared_zone_name_is_eligible_once_the_operator_opts_in(bool $staging): void
    {
        $this->settings('1');

        $this->assertNull(ProjectCertificate::issuer($staging)->ineligibleReason(self::SHARED));
    }

    #[DataProvider('modes')]
    public function test_a_shared_zone_name_stays_skipped_while_the_setting_is_off(bool $staging): void
    {
        $this->settings(null);

        $this->assertNotNull(ProjectCertificate::issuer($staging)->ineligibleReason(self::SHARED));
    }

    #[DataProvider('modes')]
    public function test_staging_and_production_reach_their_own_authority(bool $staging): void
    {
        $this->settings(null);

        $url = (new \ReflectionProperty(AcmeIssuer::class, 'directoryUrl'))->getValue(ProjectCertificate::issuer($staging));
        $this->assertSame($staging ? AcmeIssuer::LETS_ENCRYPT_STAGING : AcmeIssuer::LETS_ENCRYPT, $url);
    }

    /** The renewal must not build an issuer of its own: the constructor's default refuses shared zones. */
    public function test_the_renewal_takes_its_issuer_from_issuance(): void
    {
        $renew = (string) file_get_contents(app_path('Console/Commands/Ssl/ProjectCertRenew.php'));

        $this->assertStringNotContainsString('new AcmeIssuer(', $renew);
        $this->assertStringContainsString('ProjectCertificate::issuer(', $renew);
    }

    private function settings(?string $sharedZone): void
    {
        Setting::setRuntimeSettings([
            'ssl_shared_zone_issuance' => $sharedZone,
            'acme_directory_url' => null,
            'acme_email' => null,
            'cert_email' => null,
            'email' => null,
        ]);
    }
}
