<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Deployment\FailureDisposition;
use App\System\Project\Deployment\RollBackProject;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A container project whose account image failed to build had its account
 * removed again, but the failed deploy kept the project row: the API went on
 * answering "active" for a project with no home and no account container.
 */
class FailedAccountCreateTest extends TestCase
{
    private string $username = '';

    private ?DeployLogger $deploy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'facc-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_an_account_that_failed_to_build_is_rolled_back_not_kept(): void
    {
        $project = $this->project();
        $project->shouldReceive('up')->once()->andThrow(new \Exception('A build step failed (exit code 1)'));
        $project->shouldReceive('delete')->once();
        $mechanics = new DindDeployMechanics($this->dind($project));

        $this->runExpecting($mechanics, $this->keepsNothing(), 'deploy_failed');

        $this->assertTrue($mechanics->removedTheAccount());
        $this->assertInstanceOf(RollBackProject::class, $this->dispositionFor($mechanics));
    }

    public function test_a_create_cancelled_while_the_account_was_built_is_rolled_back_too(): void
    {
        $project = $this->project();
        $project->shouldReceive('up')->once()->andThrow(new DeployCancelledException('operator cancelled'));
        $project->shouldReceive('delete')->once();
        $mechanics = new DindDeployMechanics($this->dind($project));

        $this->runExpecting($mechanics, $this->keepsNothing(), 'deploy_cancelled');

        $this->assertTrue($mechanics->removedTheAccount());
    }

    /** The account build runs to its end whatever a cancel says; the cancel is honoured then, as one that stopped it. */
    public function test_a_cancel_asked_for_while_the_account_was_built_rolls_the_create_back(): void
    {
        $project = $this->accountMadeInFull();
        $project->shouldReceive('delete')->once();
        $mechanics = new DindDeployMechanics($this->dind($project));
        $this->deploy = DeployLogger::start($this->username);
        DeployLogger::requestCancel($this->username);

        $this->runExpecting($mechanics, $this->keepsNothing(), 'deploy_cancelled');

        $this->assertTrue($mechanics->removedTheAccount());
    }

    public function test_an_account_built_with_no_cancel_is_kept(): void
    {
        $project = $this->accountMadeInFull();
        $project->shouldNotReceive('delete');
        $mechanics = new DindDeployMechanics($this->dind($project));
        $this->deploy = DeployLogger::start($this->username);

        $mechanics->prepareHostingEnvironment();

        $this->assertFalse($mechanics->removedTheAccount());
    }

    /** The account was made and stays: the failed project is kept, as for any failed deploy. */
    public function test_a_failure_after_the_account_was_made_still_keeps_the_project(): void
    {
        $project = $this->project();
        $project->shouldReceive('up')->once();
        $project->shouldReceive('fixPermissions')->once();
        $project->shouldReceive('configureQuota')->once();
        $project->shouldReceive('waitForAllRunning')->once()->andThrow(new \Exception('the account did not come up'));
        $project->shouldNotReceive('delete');
        $mechanics = new DindDeployMechanics($this->dind($project));
        $retain = Mockery::mock(FailureDisposition::class);
        $retain->shouldReceive('afterFailure')->once();

        $this->runExpecting($mechanics, $retain, 'deploy_failed');

        $this->assertFalse($mechanics->removedTheAccount());
    }

    private function accountMadeInFull(): SystemProject&Mockery\MockInterface
    {
        $project = $this->project();
        $project->shouldReceive('up')->once();
        $project->shouldReceive('fixPermissions')->once();
        $project->shouldReceive('configureQuota')->once();
        $project->shouldReceive('waitForAllRunning')->once();

        return $project;
    }

    /** What a DinD deploy does with a failed project, here required to be left out. */
    private function keepsNothing(): FailureDisposition
    {
        $retain = Mockery::mock(FailureDisposition::class);
        $retain->shouldNotReceive('afterFailure');
        $retain->shouldNotReceive('afterCancelled');

        return $retain;
    }

    private function runExpecting(DindDeployMechanics $mechanics, FailureDisposition $retain, string $code): void
    {
        try {
            DeploymentWorkflow::forMechanics($mechanics, $retain)->run();
            $this->fail('Expected the create to fail');
        } catch (ProblemException $e) {
            $this->assertSame($code, $e->problems[0]['code'] ?? null);
        }
    }

    private function dispositionFor(DindDeployMechanics $mechanics): object
    {
        $workflow = DeploymentWorkflow::forMechanics($mechanics, Mockery::mock(FailureDisposition::class));

        return (new ReflectionMethod($workflow, 'dispositionFor'))->invoke($workflow, $mechanics);
    }

    /** A new account, prepared up to the step that builds and starts its container. */
    private function project(): SystemProject&Mockery\MockInterface
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(false);
        $project->shouldReceive('createDirectories')->once();
        $project->shouldReceive('syncLinuxUser')->once()->andReturn(['UID' => 1000, 'GID' => 1000]);
        $project->shouldReceive('model')->andReturn(Mockery::mock(ModelsUser::class, ['save' => true]));
        $project->shouldReceive('createFromTemplate')->once();

        return $project;
    }

    /** An unsaved project: the rollback finds no row to delete, so only which disposition ran is seen. */
    private function dind(SystemProject $project): Dind
    {
        $user = Mockery::mock(ModelsUser::class)->makePartial();
        $user->shouldReceive('getMainDomain')->andReturn(new DomainModel);
        $user->shouldReceive('usedCustomEnvVars')->andReturn(false);
        $user->shouldReceive('getKey')->andReturn(null);
        $user->shouldReceive('getAttribute')->with('username')->andReturn('shop');

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('project')->andReturn($project);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }
}
