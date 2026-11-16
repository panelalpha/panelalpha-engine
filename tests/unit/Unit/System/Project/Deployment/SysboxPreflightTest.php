<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\ProblemException;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Privileged;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Deployment\FailureDisposition;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\SysboxDaemons;
use Mockery;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * With sysbox-fs down a create built an account that could not start, and a redeploy built for minutes before its
 * first mount failed: both are refused before they touch the account, once per deploy.
 */
class SysboxPreflightTest extends TestCase
{
    private const DOWN = "Id=sysbox-fs.service\nLoadState=loaded\nActiveState=failed\n\n"
        ."Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=active\n";

    private const RUNNING = "Id=sysbox-fs.service\nLoadState=loaded\nActiveState=active\n\n"
        ."Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=active\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_RUNTIME' => 'sysbox-runc']);
    }

    public function test_a_create_while_sysbox_is_down_makes_no_account_and_keeps_no_project(): void
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(false);
        $project->shouldNotReceive('createDirectories', 'createFromTemplate', 'up', 'delete');
        $mechanics = new DindDeployMechanics($this->dind($project, $this->host(self::DOWN)));
        $kept = Mockery::mock(FailureDisposition::class);
        $kept->shouldNotReceive('afterFailure');

        try {
            DeploymentWorkflow::forMechanics($mechanics, $kept)->run();
            $this->fail('a create while sysbox is down must be refused');
        } catch (ProblemException $e) {
            $this->assertSame('sysbox-not-running', $e->problems[0]['code'] ?? null);
            $this->assertStringStartsWith('The sysbox runtime is not running on the engine host (sysbox-fs failed)', $e->getMessage());
        }
        $this->assertTrue($mechanics->removedTheAccount());
    }

    public function test_a_create_with_sysbox_running_probes_it_once(): void
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(false);
        $project->shouldReceive('createDirectories', 'createFromTemplate', 'up', 'fixPermissions', 'configureQuota', 'waitForAllRunning')->once();
        $project->shouldReceive('syncLinuxUser')->once()->andReturn(['UID' => 1000, 'GID' => 1000]);
        $project->shouldReceive('model')->andReturn(Mockery::mock(ModelsUser::class, ['save' => true]));
        $host = $this->host(self::RUNNING);

        (new DindDeployMechanics($this->dind($project, $host)))->prepareHostingEnvironment();

        $this->assertSame([Privileged::onHost(Privileged::probes()->sysboxStates())], $host->commands);
    }

    public function test_a_redeploy_while_sysbox_is_down_is_refused_before_it_touches_the_account(): void
    {
        $dind = $this->dind(Mockery::mock(SystemProject::class), $this->host(self::DOWN));
        $dind->shouldNotReceive('shell');

        $this->expectExceptionMessage(SysboxDaemons::NOT_RUNNING.' (sysbox-fs failed)');

        (new DindDeployMechanics($dind))->recordRunningVersion();
    }

    private function host(string $output): RecordingSystem
    {
        $host = new RecordingSystem;
        $host->output = $output;

        return $host;
    }

    private function dind(SystemProject $project, RecordingSystem $host): Dind&Mockery\MockInterface
    {
        $user = Mockery::mock(ModelsUser::class)->makePartial();
        $user->shouldReceive('getMainDomain')->andReturn(new DomainModel);
        $user->shouldReceive('usedCustomEnvVars')->andReturn(false);
        $user->shouldReceive('getKey')->andReturn(null);
        $user->shouldReceive('getAttribute')->with('username')->andReturn('shop');

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('project')->andReturn($project);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('username')->andReturn('shop');
        $dind->shouldReceive('system')->andReturn($host);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind))->byDefault();
        $dind->shouldReceive('innerDockerIsUp')->andReturn(true);

        return $dind;
    }
}
