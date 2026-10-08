<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\CheckoutRedeploy;
use Tests\TestCase;
use Tests\Unit\System\Project\FakeGitRunner;

class DindCheckoutRedeployTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }
    public function test_deploy_managed_mutation_records_rebuild(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
        ]);
        $project = $this->dind($model);
        $git = new TestableGitRepository($project, new FakeGitRunner());
        $redeploy = new RecordingDindCheckoutRedeploy();

        $this->assertTrue($git->isDeployManaged());
        $redeploy->afterMutation($git, $project);

        $this->assertSame(['alice'], $redeploy->usernames);
    }

    public function test_site_git_mutation_does_not_rebuild(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'site_git' => [
                'project' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat',
                ],
            ],
        ]);
        $project = $this->dind($model);
        $git = new TestableGitRepository($project, new FakeGitRunner());
        $redeploy = new RecordingDindCheckoutRedeploy();

        $this->assertFalse($git->isDeployManaged());
        $redeploy->afterMutation($git, $project);

        $this->assertSame([], $redeploy->usernames);
    }

    public function test_source_access_delegates_after_git_mutation(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => 'express',
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
        ]);
        $project = $this->dind($model);
        $source = $project->app()->source();
        $git = $source->git();

        $this->assertTrue($git->isDeployManaged());
        $this->assertTrue(method_exists($source, 'afterGitMutation'));
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        $tmp = sys_get_temp_dir() . '/pa-dind-redeploy-' . bin2hex(random_bytes(4));

        return new class ($tmp, $tmp . '/home') extends System {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->homesRoot;
            }
        };
    }
}

final class RecordingDindCheckoutRedeploy extends CheckoutRedeploy
{
    /** @var list<string> */
    public array $usernames = [];

    protected function rebuild(Dind $project): void
    {
        $this->usernames[] = $project->username();
    }
}
