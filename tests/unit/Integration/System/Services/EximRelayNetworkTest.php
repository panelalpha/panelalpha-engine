<?php

namespace Tests\Integration\System\Services;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Setting;
use App\System\Privileged;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Exim's relay as a container project sees it, read from Docker, and the sender name it falls back to from the stored settings. */
class EximRelayNetworkTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_tenant_network_docker_cannot_inspect_gives_no_relay(): void
    {
        $system = $this->system(FakeProcess::failed('Error: No such network: pash-tenants'));

        $this->assertSame([], (new Exim($system))->relayEnvironment());
        $this->assertSame([Privileged::docker()->networkSubnetsAndGateways('pash-tenants')], $system->commands);
    }

    public function test_the_relay_is_the_tenant_networks_gateway(): void
    {
        $gateway = TestNetwork::private(1);
        $system = $this->system(FakeProcess::ok(TestNetwork::prefix($gateway, 16)." {$gateway}\n"));

        $this->assertSame(
            [ComposeEnvironment::SMTP_HOST => $gateway, ComposeEnvironment::SMTP_PORT => '25'],
            (new Exim($system))->relayEnvironment()
        );
    }

    public function test_the_sender_name_comes_from_the_stored_exim_settings(): void
    {
        Setting::setRuntimeSettings(['exim' => json_encode(['sender_domain' => ' Mail.Example.Test '])]);

        $this->assertSame('mail.example.test', (new Exim(new RecordingSystem))->senderName());
    }

    public function test_without_stored_exim_settings_the_sender_name_is_the_certificate_name(): void
    {
        Setting::setRuntimeSettings(['cert_domain' => 'Engine.Example.Test']);

        $this->assertSame('engine.example.test', (new Exim(new RecordingSystem))->senderName());
    }

    private function system(Process $answer): RecordingSystem
    {
        return new class ($answer) extends RecordingSystem {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function __construct(private Process $answer) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;

                return $this->answer;
            }
        };
    }
}
