<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind\SysboxDaemons;
use Symfony\Component\Process\Process;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * A deploy into a sysbox account while sysbox-fs or sysbox-mgr is down builds for minutes and then fails on a mount;
 * one host probe refuses it at once instead, and a probe that cannot tell never refuses.
 */
class SysboxDaemonsTest extends TestCase
{
    /** What `systemctl show` answered on a healthy engine host. */
    private const RUNNING = "Id=sysbox-fs.service\nLoadState=loaded\nActiveState=active\n\n"
        ."Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=active\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_RUNTIME' => 'sysbox-runc']);
    }

    public function test_running_daemons_let_the_deploy_go_on_after_one_probe(): void
    {
        $system = $this->host(self::RUNNING);

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->assertSame([Privileged::onHost(Privileged::probes()->sysboxStates())], $system->commands);
    }

    public function test_a_failed_sysbox_fs_refuses_the_deploy_with_what_the_explainer_names(): void
    {
        $system = $this->host("ActiveState=failed\nId=sysbox-fs.service\nLoadState=loaded\n\n"
            ."LoadState=loaded\nActiveState=active\nId=sysbox-mgr.service\n");

        $message = $this->refusal($system);

        $this->assertSame('sysbox is not running on the engine host (sysbox-fs failed)', $message);
        $this->assertSame('sysbox-not-running', DeployFailureExplainer::match($message)['rule'] ?? null);
    }

    public function test_both_daemons_down_are_both_named(): void
    {
        $system = $this->host("Id=sysbox-fs.service\nLoadState=loaded\nActiveState=inactive\n\n"
            ."Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=failed\n");

        $this->assertSame('sysbox is not running on the engine host (sysbox-fs inactive, sysbox-mgr failed)', $this->refusal($system));
    }

    /** A host without these units, as `systemctl show` answers for one it does not have. */
    public function test_a_daemon_systemd_does_not_know_is_not_called_down(): void
    {
        $system = $this->host("Id=sysbox-fs.service\nLoadState=not-found\nActiveState=inactive\n\n"
            ."Id=sysbox-mgr.service\nLoadState=not-found\nActiveState=inactive\n");

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->assertCount(1, $system->commands);
    }

    public function test_a_daemon_on_its_way_up_is_not_called_down(): void
    {
        $system = $this->host("Id=sysbox-fs.service\nLoadState=loaded\nActiveState=activating\n\n"
            ."Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=active\n");

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->assertCount(1, $system->commands);
    }

    public function test_a_probe_the_host_refused_lets_the_deploy_go_on(): void
    {
        $system = $this->host("pa-root: unknown action 'probes.sysboxStates'", exitCode: 2);

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->assertCount(1, $system->commands);
    }

    public function test_a_probe_that_could_not_run_lets_the_deploy_go_on(): void
    {
        $system = new class extends System
        {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('The command could not be started.');
            }
        };

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->addToAssertionCount(1);
    }

    /** The same probe tells when each daemon last became active; a unit systemd does not have is not one of them. */
    public function test_the_loaded_daemons_carry_their_state_and_last_start(): void
    {
        $system = $this->host("Id=sysbox-fs.service\nLoadState=loaded\nActiveState=active\nActiveEnterTimestamp=Sat 2026-11-07 18:05:00 UTC\n\n"
            ."Id=sysbox-mgr.service\nLoadState=not-found\nActiveState=inactive\nActiveEnterTimestamp=\n");

        $this->assertSame(
            ['sysbox-fs' => ['state' => 'active', 'since' => 'Sat 2026-11-07 18:05:00 UTC']],
            (new SysboxDaemons($system))->loaded()
        );
        $this->assertSame([Privileged::onHost(Privileged::probes()->sysboxStates())], $system->commands);
    }

    public function test_a_privileged_runtime_is_never_probed(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $system = $this->host("Id=sysbox-fs.service\nLoadState=loaded\nActiveState=failed\n");

        (new SysboxDaemons($system))->refuseWhenStopped();

        $this->assertSame([], $system->commands);
    }

    private function refusal(System $system): string
    {
        try {
            (new SysboxDaemons($system))->refuseWhenStopped();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('a stopped daemon must refuse the deploy');
    }

    private function host(string $output, int $exitCode = 0): RecordingSystem
    {
        $system = new RecordingSystem;
        $system->output = $output;
        $system->exitCode = $exitCode;

        return $system;
    }
}
