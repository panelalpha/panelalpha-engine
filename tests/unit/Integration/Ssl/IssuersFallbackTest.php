<?php

namespace Tests\Integration\Ssl;

use App\Exceptions\ProblemException;
use App\Lib\Ssl\EngineCertificateRequest;
use App\Lib\Ssl\IssuableDomain;
use App\Lib\Ssl\Issuers;
use App\Lib\Ssl\ProjectCertificate;
use App\Lib\Ssl\SelfSignedIssuer;
use App\Models\Domain;
use App\Models\Setting;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecordingSystem;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The self-signed fallbacks around ACME, and the engine certificate request's refusal and answer. */
class IssuersFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_an_ineligible_name_under_acme_is_logged_and_self_signed_without_asking_an_authority(): void
    {
        Setting::setRuntimeSettings([
            'ssl_issuer' => 'acme',
            'ssl_shared_zone_issuance' => null,
            'acme_directory_url' => null,
            'acme_email' => null,
            'cert_email' => null,
            'email' => null,
        ]);
        Log::spy();
        $domain = $this->createMock(IssuableDomain::class);
        $domain->method('model')->willReturn(new Domain(['domain' => 'shop.local']));
        $domain->expects($this->once())->method('generateSelfSignedCertificate');
        $domain->expects($this->never())->method('putIssuedCertificate');
        $domain->expects($this->never())->method('publishCertificateToHostWebserver');

        $this->assertSame(SelfSignedIssuer::ID, Issuers::issueFor($domain));
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Not requesting a certificate for shop.local: ')
        );
    }

    public function test_removing_the_certificate_of_a_domain_with_ssl_off_is_refused(): void
    {
        $domain = new Domain(['domain' => 'shop.example.com', 'details' => ['ssl_disabled' => true]]);

        try {
            ProjectCertificate::remove($domain);
            $this->fail('a domain with SSL off has no certificate to put back');
        } catch (ProblemException $e) {
            $this->assertSame('ssl_disabled', $e->problems[0]['code']);
            $this->assertSame('shop.example.com has SSL turned off, so it has no certificate to put back.', $e->problems[0]['message']);
        }
    }

    public function test_a_value_the_gateway_refuses_runs_nothing(): void
    {
        Setting::setRuntimeSettings(['cert_domain' => 'engine.example.com']);
        $system = new RecordingSystem;

        $result = EngineCertificateRequest::run($system, ['domain' => 'not a host name']);

        $this->assertFalse($result['successful']);
        $this->assertNotSame('', $result['output']);
        $this->assertSame('engine.example.com', $result['cert_domain']);
        $this->assertSame([], $system->commands);
    }

    public function test_the_request_runs_the_host_script_and_reports_its_readable_output(): void
    {
        Setting::setRuntimeSettings(['cert_domain' => 'engine.example.com']);
        $system = new RecordingSystem;
        $system->output = "\e[32mCertificate issued\e[39m";
        $values = ['domain' => 'engine.example.com', 'email' => 'ops@example.com', 'ip' => TestNetwork::public(7)];

        $result = EngineCertificateRequest::run($system, $values, ['staging', 'bogus']);

        $this->assertSame(['successful' => true, 'output' => 'Certificate issued', 'cert_domain' => 'engine.example.com'], $result);
        $this->assertSame(
            [Privileged::host()->requestEngineCertificate('/opt/panelalpha/shared-hosting', $values, ['staging'])],
            $system->commands
        );
    }
}
