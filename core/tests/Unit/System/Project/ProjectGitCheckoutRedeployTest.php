<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\CheckoutRedeploy;
use Tests\TestCase;
use Tests\Unit\System\Project\FakeGitRunner;

class ProjectGitCheckoutRedeployTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_deploy_managed_mutation_rebuilds_from_checkout(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
            'template' => 'dind',
        ]);
        $project = new Project(new System(), $model);
        $git = new TestableProjectGit($project, 'project', new FakeGitRunner());
        $redeploy = new RecordingProjectCheckoutRedeploy();

        $this->assertTrue($git->isDeployManaged());
        $redeploy->afterMutation($git, $project);

        $this->assertSame(['alice'], $redeploy->usernames);
    }

    public function test_site_git_mutation_does_not_rebuild(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->putSiteGit('public_html', [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => 'pat-secret',
        ]);
        $project = new Project(new System(), $model);
        $git = new TestableProjectGit($project, 'public_html', new FakeGitRunner());
        $redeploy = new RecordingProjectCheckoutRedeploy();

        $this->assertFalse($git->isDeployManaged());
        $redeploy->afterMutation($git, $project);

        $this->assertSame([], $redeploy->usernames);
    }
}

final class RecordingProjectCheckoutRedeploy extends CheckoutRedeploy
{
    /** @var list<string> */
    public array $usernames = [];

    protected function rebuild(Project $project, string $source = 'git', ?string $commit = null, ?DeployLogger $deployLogger = null): void
    {
        $this->usernames[] = $project->username();
    }
}
