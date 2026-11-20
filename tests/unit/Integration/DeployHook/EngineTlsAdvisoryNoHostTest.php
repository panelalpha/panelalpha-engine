<?php

namespace Tests\Integration\DeployHook;

use App\Lib\DeployHook\EngineTlsAdvisory;
use App\Lib\Ssl\EngineCertificate;
use Tests\TestCase;

/** An engine URL with no host cannot be reached by a git host, and the advisory says so. */
class EngineTlsAdvisoryNoHostTest extends TestCase
{
    public function test_an_engine_url_without_a_host_carries_the_warning(): void
    {
        $this->app->instance(EngineCertificate::class, new EngineCertificate(new CertificateEngine()));
        config(['app.url' => '']);

        $advisory = EngineTlsAdvisory::forProvider('github');

        $this->assertSame(EngineTlsAdvisory::SELF_SIGNED, $advisory['state']);
        $this->assertSame('This engine has no public address, so a git host on the internet cannot reach this Deploy Hook.', $advisory['warning']);
        $this->assertSame(['github'], array_keys($advisory['instructions']));
    }
}
