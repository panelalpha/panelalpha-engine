<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Models\Setting;
use App\System;
use App\System\Services\Webserver;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * A fact whose source throws is reported absent, never as a failure of the whole envelope.
 */
class HostFactsUnavailableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HostFacts::resetProbeCache();
        Setting::flushCache();
        config(['database.default' => 'pa-no-such-connection']);
    }

    protected function tearDown(): void
    {
        HostFacts::resetProbeCache();
        Setting::flushCache();
        parent::tearDown();
    }

    private function system(): System
    {
        return new class extends System
        {
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '{"ID":"daemon-1","Name":"node-1"}';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::failed('no host in this test');
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return FakeProcess::failed('no host in this test');
            }

            public function webserver(): Webserver
            {
                throw new \RuntimeException('webserver unknown');
            }
        };
    }

    public function test_the_envelope_reports_an_unreadable_webserver_and_account_count_as_null(): void
    {
        $envelope = HostFacts::envelope($this->system());

        $this->assertNull($envelope['webserver']);
        $this->assertNull($envelope['accounts']);
        $this->assertSame(PHP_VERSION, $envelope['php']);
    }

    public function test_an_unreadable_public_address_is_left_out_of_the_fingerprint_and_the_host(): void
    {
        $system = $this->system();

        $this->assertNull(HostFacts::forFingerprint($system)['public_ip']);
        $this->assertSame('daemon-1', HostFacts::forFingerprint($system)['docker_id']);
        $this->assertNull(HostFacts::host($system)['ipv4']);
        $this->assertSame('node-1', HostFacts::host($system)['hostname']);
    }
}
