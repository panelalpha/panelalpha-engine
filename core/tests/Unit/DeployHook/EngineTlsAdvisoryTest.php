<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\EngineTlsAdvisory;
use App\Lib\DeployHook\TlsInstructions;
use Tests\TestCase;

/**
 * What a client is told about the certificate a git host will see. No engine
 * certificate exists on a test box (`System::engineDirPath()` is a fixed
 * `/opt/panelalpha/...`), so `state` is deterministically `self_signed` here
 * -- the same "nothing to point to" case a fresh install is in.
 */
class EngineTlsAdvisoryTest extends TestCase
{
    public function test_with_no_engine_certificate_the_state_is_self_signed_with_every_providers_instructions(): void
    {
        config(['app.url' => 'https://8.8.8.8']);

        $advisory = EngineTlsAdvisory::forProvider();

        $this->assertSame(EngineTlsAdvisory::SELF_SIGNED, $advisory['state']);
        $this->assertSame(TlsInstructions::all(), $advisory['instructions']);
    }

    public function test_a_provider_narrows_the_instructions_to_that_one(): void
    {
        config(['app.url' => 'https://8.8.8.8']);

        $advisory = EngineTlsAdvisory::forProvider('gitlab');

        $this->assertSame(['gitlab' => TlsInstructions::for('gitlab')], $advisory['instructions']);
    }

    public function test_a_public_address_carries_no_warning(): void
    {
        config(['app.url' => 'https://8.8.8.8']);

        $this->assertNull(EngineTlsAdvisory::forProvider()['warning']);
    }

    public function test_a_private_address_warns_that_a_git_host_cannot_reach_it(): void
    {
        config(['app.url' => 'https://192.168.1.50']);

        $warning = EngineTlsAdvisory::forProvider()['warning'];

        $this->assertNotNull($warning);
        $this->assertStringContainsString('no public address', $warning);
    }

    public function test_a_hostname_is_assumed_reachable_and_carries_no_warning(): void
    {
        config(['app.url' => 'https://engine.example.test']);

        $this->assertNull(EngineTlsAdvisory::forProvider()['warning']);
    }
}
