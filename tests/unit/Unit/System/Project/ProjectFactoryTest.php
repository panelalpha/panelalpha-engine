<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Cron;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication as DindApp;
use App\System\Project\Domain as DomainCollaborator;
use App\System\Project\FileManager;
use App\System\Project\Ftp;
use App\System\Project\Git as ProjectGit;
use App\System\Project\PhpHosting;
use App\System\Project\Settings;
use App\System\Project\Sftp;
use App\Models\Domain as DomainModel;
use PHPUnit\Framework\TestCase;

class ProjectFactoryTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-factory-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_system_project_returns_aggregate_not_implementation(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->phpHostingModel('alice');

        $project = $system->project($model);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertNotInstanceOf(Dind::class, $project);
        $this->assertNotInstanceOf(PhpHosting::class, $project);
    }

    public function test_constructor_returns_same_aggregate_type(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->phpHostingModel('bob');

        $project = new Project($system, $model);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertNotInstanceOf(PhpHosting::class, $project);
    }

    public function test_username_and_model_accessors_follow_users_row(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->phpHostingModel('carol');

        $project = $system->project($model);

        $this->assertSame('carol', $project->username());
        $this->assertSame($model, $project->model());
        $this->assertSame($system, $project->system());
    }

    public function test_exists_is_true_only_when_outer_compose_file_is_present(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->dindModel('alice');

        $project = $system->project($model);

        $this->assertFalse($project->exists());

        file_put_contents($project->composeFilePath(), "services: {}\n");
        $this->assertTrue($project->exists());
    }

    public function test_dind_git_project_app_is_null_without_deploy_strategy(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->dindModel('alice', gitRepo: 'https://github.com/example/app.git');

        $project = $system->project($model);

        $this->assertNull($this->dindApp($project));
    }

    public function test_dind_git_project_app_is_dind_application_when_deploy_strategy_set(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->dindModel(
            'alice',
            gitRepo: 'https://github.com/example/app.git',
            deployStrategy: 'express',
        );

        $project = $system->project($model);
        $app = $this->dindApp($project);

        $this->assertInstanceOf(DindApp::class, $app);
    }

    public function test_dind_template_selects_dind_app_behaviour_without_git(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->dindModel('alice', template: 'dind', deployStrategy: 'php');

        $project = $system->project($model);

        $this->assertInstanceOf(DindApp::class, $this->dindApp($project));
    }

    public function test_collaborators_are_exposed_on_aggregate_not_implementation_type(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $model = $this->phpHostingModel('alice');

        $project = $system->project($model);

        $this->assertInstanceOf(Cron::class, $project->cron());
        $this->assertInstanceOf(Settings::class, $project->settings());
        $this->assertInstanceOf(FileManager::class, $project->fileManager());
        $this->assertInstanceOf(Ftp::class, $project->ftp());
        $this->assertInstanceOf(Sftp::class, $project->sftp());
        $this->assertInstanceOf(ProjectGit::class, $project->git());
        $this->assertSame('/home/alice/public_html', $project->git()->absolutePath());
        $this->assertSame('public_html', $project->git()->pathKey());

        $domainModel = new DomainModel();
        $domainModel->domain = 'app.example.test';
        $collaborator = $project->domain($domainModel);
        $this->assertInstanceOf(DomainCollaborator::class, $collaborator);
        $this->assertSame($project, $collaborator->project());
    }

    /** Only a DinD runtime has an application. */
    private function dindApp(Project $project): ?DindApp
    {
        $runtime = $project->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime->app();
    }

    private function systemWithEngineRoot(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }
        };
    }

    private function dindModel(
        string $username,
        ?string $template = null,
        ?string $deployStrategy = null,
        ?string $gitRepo = null,
    ): ModelsUser {
        $model = new ModelsUser();
        $model->username = $username;
        $details = [];
        if ($deployStrategy !== null) {
            $details['deploy_strategy'] = $deployStrategy;
        }
        if ($gitRepo !== null) {
            $details['git_repo'] = $gitRepo;
        }
        if ($template !== null) {
            $details['template'] = $template;
        }
        if ($details !== []) {
            $model->setDetails($details);
        }

        return $model;
    }

    private function phpHostingModel(string $username): ModelsUser
    {
        // No main domain: git falls back to ~/public_html, and no database is asked.
        $model = self::getStubBuilder(ModelsUser::class)->onlyMethods(['getMainDomain'])->getStub();
        $model->method('getMainDomain')->willReturn(null);
        $model->username = $username;

        return $model;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
