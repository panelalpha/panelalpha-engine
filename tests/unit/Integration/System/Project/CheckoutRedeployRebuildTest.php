<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\CheckoutRedeploy as SourceCheckoutRedeploy;
use App\System\Project\Git\CheckoutRedeploy;
use App\System\Project\Runtime;
use App\System\Services\Webserver;
use Tests\Integration\System\Project\Deployment\RecordingDisposition;
use Tests\Integration\System\Project\Deployment\RecordingMechanics;
use Tests\Support\FakeGitRunner;
use Tests\Support\TestableGitRepository;
use Tests\Support\TestableProjectGit;
use Tests\TestCase;

/**
 * A change to a Deploy-managed checkout rebuilds the app from the files in it, through the real deploy workflow
 * over recorded mechanics: the webserver's domains follow, and the project records the rebuild's verdict.
 */
class CheckoutRedeployRebuildTest extends TestCase
{
    private string $username = '';

    private ?RecordingMechanics $mechanics = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->username = 'redeploy-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_pushed_change_rebuilds_from_the_checkout_and_records_the_success(): void
    {
        $user = $this->user('failed');
        $runner = new FakeGitRunner();
        $runner->stdout['branch --show-current'] = "release\n";
        $git = new TestableProjectGit(new Project(new System(), $user), 'project', $runner);

        (new CheckoutRedeploy())->afterMutation($git, $this->project($user, rebuildsDomains: true), 'push', 'abc1234def');

        $this->assertSame(['syncCheckoutRebuild', 'reprepare', 'start', 'persistSuccess', 'settle'], $this->mechanics->calls);
        $this->assertSame('success', $user->getDeploymentStatus());
        $this->assertSame(1, $user->saves);
        $latest = DeployLogger::readLatestFor($this->username);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, $latest['status']);
        $start = array_column(DeployLogger::forDeploy($this->username, $latest['id'])->tail(50), 'msg')[0];
        $this->assertStringContainsString('abc1234', $start);
        $this->assertStringContainsString('release', $start);
    }

    public function test_a_partial_verdict_the_rebuild_recorded_is_not_turned_into_a_success(): void
    {
        $user = $this->user('partial');
        $git = new TestableProjectGit(new Project(new System(), $user), 'project', new FakeGitRunner());

        (new CheckoutRedeploy())->afterMutation($git, $this->project($user, rebuildsDomains: true));

        $this->assertSame('partial', $user->getDeploymentStatus());
        $this->assertSame(0, $user->saves);
    }

    public function test_a_project_that_is_not_a_container_project_is_not_rebuilt(): void
    {
        $user = $this->user('failed');
        $git = new TestableProjectGit(new Project(new System(), $user), 'project', new FakeGitRunner());
        $project = $this->createStub(Project::class);
        $project->method('runtime')->willReturn($this->createStub(Runtime::class));
        $project->method('model')->willReturn($user);

        (new CheckoutRedeploy())->afterMutation($git, $project);

        $this->assertSame('failed', $user->getDeploymentStatus());
        $this->assertSame(0, $user->saves);
        $this->assertNull(DeployLogger::readLatestFor($this->username));
    }

    public function test_a_stopped_account_is_started_before_its_checkout_changes(): void
    {
        $user = $this->user('success');
        $git = new TestableProjectGit(new Project(new System(), $user), 'project', new FakeGitRunner());

        (new CheckoutRedeploy())->startAccountIfStopped($git, $this->project($user), null);

        $this->assertSame(['syncCheckoutRebuild'], $this->mechanics->calls);
    }

    public function test_the_ingests_rebuild_after_a_change_records_the_success(): void
    {
        $user = $this->user('failed');
        $git = new TestableGitRepository((new Project(new System(), $user))->runtime(), new FakeGitRunner());
        $this->assertTrue($git->isDeployManaged());

        (new SourceCheckoutRedeploy())->afterMutation($git, $this->dind($user, rebuildsDomains: true));

        $this->assertSame(['syncCheckoutRebuild', 'reprepare', 'start', 'persistSuccess', 'settle'], $this->mechanics->calls);
        $this->assertSame('success', $user->getDeploymentStatus());
        $this->assertSame(1, $user->saves);
    }

    public function test_the_ingests_rebuild_keeps_a_partial_verdict(): void
    {
        $user = $this->user('partial');
        $git = new TestableGitRepository((new Project(new System(), $user))->runtime(), new FakeGitRunner());

        (new SourceCheckoutRedeploy())->afterMutation($git, $this->dind($user, rebuildsDomains: true));

        $this->assertSame('partial', $user->getDeploymentStatus());
        $this->assertSame(0, $user->saves);
    }

    /** A Deploy-managed project that exists, whose saves are counted rather than written. */
    private function user(string $status): ModelsUser
    {
        $user = new class () extends ModelsUser {
            public int $saves = 0;

            public function save(array $options = []): bool
            {
                $this->saves++;

                return true;
            }
        };
        $user->username = $this->username;
        $user->exists = true;
        $user->setDetails([
            'template' => 'dind',
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
            'deployment_status' => $status,
        ]);

        return $user;
    }

    private function project(ModelsUser $user, bool $rebuildsDomains = false): Project
    {
        $dind = $this->dind($user, $rebuildsDomains);
        $project = $this->createStub(Project::class);
        $project->method('runtime')->willReturn($dind);
        $project->method('system')->willReturn($dind->system());
        $project->method('model')->willReturn($user);

        return $project;
    }

    private function dind(ModelsUser $user, bool $rebuildsDomains = false): Dind
    {
        $this->mechanics = new RecordingMechanics($user, new DomainModel());
        $webserver = $this->createMock(Webserver::class);
        $webserver->expects($rebuildsDomains ? $this->once() : $this->never())->method('rebuildDomains');
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);
        $dind = $this->createStub(Dind::class);
        $dind->method('deployment')->willReturn(DeploymentWorkflow::forMechanics($this->mechanics, new RecordingDisposition()));
        $dind->method('system')->willReturn($system);
        $dind->method('userModel')->willReturn($user);
        $dind->method('username')->willReturn($this->username);

        return $dind;
    }
}
