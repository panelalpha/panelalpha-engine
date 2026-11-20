<?php

namespace Tests\Integration\DeployHook;

use App\Lib\DeployHook\EngineTlsAdvisory;
use App\Lib\DeployHook\TlsInstructions;
use App\Lib\Ssl\EngineCertificate;
use Tests\Support\TestNetwork;
use Tests\TestCase;
use Tests\Integration\Ssl\TestCertificates;

/**
 * What a client is told about the certificate a git host will see; the fake
 * engine holds none unless a test gives it one, so `state` is `self_signed`.
 */
class EngineTlsAdvisoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EngineCertificate::class, new EngineCertificate(new CertificateEngine()));
    }

    public function test_with_no_engine_certificate_the_state_is_self_signed_with_every_providers_instructions(): void
    {
        config(['app.url' => 'https://'.TestNetwork::routable(8)]);

        $advisory = EngineTlsAdvisory::forProvider();

        $this->assertSame(EngineTlsAdvisory::SELF_SIGNED, $advisory['state']);
        $this->assertSame(TlsInstructions::all(), $advisory['instructions']);
    }

    public function test_a_provider_narrows_the_instructions_to_that_one(): void
    {
        config(['app.url' => 'https://'.TestNetwork::routable(8)]);

        $advisory = EngineTlsAdvisory::forProvider('gitlab');

        $this->assertSame(['gitlab' => TlsInstructions::for('gitlab')], $advisory['instructions']);
    }

    public function test_a_public_address_carries_no_warning(): void
    {
        config(['app.url' => 'https://'.TestNetwork::routable(8)]);

        $this->assertNull(EngineTlsAdvisory::forProvider()['warning']);
    }

    public function test_a_private_address_warns_that_a_git_host_cannot_reach_it(): void
    {
        config(['app.url' => 'https://'.TestNetwork::private2(50)]);

        $warning = EngineTlsAdvisory::forProvider()['warning'];

        $this->assertNotNull($warning);
        $this->assertStringContainsString('no public address', $warning);
    }

    public function test_a_private_ipv6_address_warns_that_a_git_host_cannot_reach_it(): void
    {
        config(['app.url' => 'https://[fd00::1]']);

        $warning = EngineTlsAdvisory::forProvider()['warning'];

        $this->assertNotNull($warning);
        $this->assertStringContainsString('no public address', $warning);
    }

    public function test_a_hostname_is_assumed_reachable_and_carries_no_warning(): void
    {
        config(['app.url' => 'https://engine.example.test']);

        $this->assertNull(EngineTlsAdvisory::forProvider()['warning']);
    }

    /** `/system/info` reads the certificate as root through the engine; a file the PHP user cannot read must not differ. */
    public function test_the_certificate_is_read_through_the_engine_as_system_info_reads_it(): void
    {
        config(['app.url' => 'https://engine.example.test']);
        $engine = new CertificateEngine(TestCertificates::selfSigned(['engine.example.test'])['cert']);
        $this->app->instance(EngineCertificate::class, new EngineCertificate($engine));

        $advisory = EngineTlsAdvisory::forProvider();

        $this->assertSame(['/opt/panelalpha/shared-hosting/crt/server.cert'], $engine->reads);
        $this->assertSame(EngineTlsAdvisory::SELF_SIGNED, $advisory['state']);
    }
}
