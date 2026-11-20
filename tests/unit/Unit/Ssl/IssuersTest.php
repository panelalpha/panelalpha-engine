<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\AcmeIssuer;
use App\Lib\Ssl\IssuableDomain;
use App\Lib\Ssl\Issuers;
use App\Lib\Ssl\ProjectCertificate;
use App\Lib\Ssl\SelfSignedIssuer;
use App\Models\Domain;
use App\Models\Setting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Which issuer, authority and account email the settings select, read from runtime settings only. */
class IssuersTest extends TestCase
{
    private const NONE = [
        'ssl_issuer' => null,
        'ssl_shared_zone_issuance' => null,
        'acme_directory_url' => null,
        'acme_email' => null,
        'cert_email' => null,
        'email' => null,
    ];

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $settings */
    private static function settings(array $settings = []): void
    {
        Setting::setRuntimeSettings($settings + self::NONE);
    }

    public function test_an_unset_issuer_is_self_signed(): void
    {
        self::settings();

        $this->assertInstanceOf(SelfSignedIssuer::class, Issuers::configured());
        $this->assertSame(SelfSignedIssuer::ID, Issuers::configured()->id());
    }

    public function test_an_unknown_issuer_is_self_signed(): void
    {
        self::settings(['ssl_issuer' => 'certbot']);

        $this->assertInstanceOf(SelfSignedIssuer::class, Issuers::configured());
    }

    public function test_acme_is_selected_case_and_space_insensitively(): void
    {
        self::settings(['ssl_issuer' => ' ACME ', 'ssl_shared_zone_issuance' => 'true']);

        $issuer = Issuers::configured();

        $this->assertInstanceOf(AcmeIssuer::class, $issuer);
        $this->assertSame(AcmeIssuer::ID, $issuer->id());
        $this->assertNull($issuer->ineligibleReason('shop.nip.io'));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function sharedZoneValues(): array
    {
        return [
            'unset' => [null, false],
            'one' => ['1', true],
            'yes' => ['yes', true],
            'zero' => ['0', false],
            'garbage' => ['maybe', false],
        ];
    }

    #[DataProvider('sharedZoneValues')]
    public function test_shared_zone_issuance_is_off_unless_a_true_value_is_set(mixed $value, bool $allowed): void
    {
        self::settings(['ssl_shared_zone_issuance' => $value]);

        $this->assertSame($allowed, Issuers::sharedZoneIssuanceAllowed());
    }

    /** @return array<string, array{?string, string}> */
    public static function directories(): array
    {
        return [
            'unset' => [null, AcmeIssuer::LETS_ENCRYPT],
            'blank' => ['  ', AcmeIssuer::LETS_ENCRYPT],
            'live' => ['live', AcmeIssuer::LETS_ENCRYPT],
            'production' => ['Production', AcmeIssuer::LETS_ENCRYPT],
            'staging' => ['STAGING', AcmeIssuer::LETS_ENCRYPT_STAGING],
            'test' => ['test', AcmeIssuer::LETS_ENCRYPT_STAGING],
            'a url' => [' https://acme.example.com/directory ', 'https://acme.example.com/directory'],
        ];
    }

    #[DataProvider('directories')]
    public function test_the_directory_url_resolves_shorthands(?string $configured, string $expected): void
    {
        self::settings(['acme_directory_url' => $configured]);

        $this->assertSame($expected, Issuers::directoryUrl());
    }

    public function test_a_shorthand_is_told_apart_from_a_url(): void
    {
        $this->assertTrue(Issuers::isDirectoryShorthand(' Staging '));
        $this->assertFalse(Issuers::isDirectoryShorthand(AcmeIssuer::LETS_ENCRYPT));
    }

    public function test_the_first_valid_email_setting_wins(): void
    {
        self::settings(['acme_email' => 'not an email', 'cert_email' => ' certs@example.com ', 'email' => 'admin@example.com']);
        $this->assertSame('certs@example.com', Issuers::email());

        self::settings(['email' => 'admin@example.com']);
        $this->assertSame('admin@example.com', Issuers::email());

        self::settings();
        $this->assertNull(Issuers::email());
    }

    public function test_issue_for_with_self_signed_configured_generates_one(): void
    {
        self::settings();
        $domain = $this->createMock(IssuableDomain::class);
        $domain->expects($this->once())->method('generateSelfSignedCertificate');
        $domain->expects($this->never())->method('putIssuedCertificate');

        $this->assertSame(SelfSignedIssuer::ID, Issuers::issueFor($domain));
    }

    public function test_the_plan_names_the_authority_email_and_challenge_without_issuing(): void
    {
        self::settings(['acme_directory_url' => 'staging', 'acme_email' => 'certs@example.com']);
        $domain = new Domain(['domain' => 'shop.example.com']);

        $this->assertSame([
            'domain' => 'shop.example.com',
            'authority' => AcmeIssuer::LETS_ENCRYPT_STAGING,
            'account_email' => 'certs@example.com',
            'challenge' => 'http-01',
            'challenge_url' => 'http://shop.example.com/.well-known/acme-challenge/',
            'ineligible_reason' => null,
        ], ProjectCertificate::plan($domain));
    }

    public function test_a_staging_plan_names_staging_and_reports_why_a_name_is_refused(): void
    {
        self::settings();
        $plan = ProjectCertificate::plan(new Domain(['domain' => 'shop.local']), true);

        $this->assertSame(AcmeIssuer::LETS_ENCRYPT_STAGING, $plan['authority']);
        $this->assertNull($plan['account_email']);
        $this->assertStringContainsString('not a public hostname', (string) $plan['ineligible_reason']);
    }

    public function test_a_request_for_a_name_no_authority_issues_for_is_refused(): void
    {
        self::settings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shop.local is not a public hostname');

        ProjectCertificate::request(new Domain(['domain' => 'shop.local']));
    }
}
