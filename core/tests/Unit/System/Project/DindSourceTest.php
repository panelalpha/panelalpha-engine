<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication as DindApp;
use App\System\Project\Dind\Source\Files;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Dind\Source\ProjectTree;
use App\System\Project\Dind\SourceAccess;
use App\System\Project\PhpHosting;
use PHPUnit\Framework\TestCase;

class DindSourceTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-source-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->coreAppRoot = dirname(__DIR__, 4) . '/app';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_source_is_reachable_only_when_application_exists(): void
    {
        $project = $this->dind($this->dindModel());
        $this->assertNull($project->app());

        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $project = $this->dind($model);
        $source = $project->app()->source();

        $this->assertInstanceOf(SourceAccess::class, $source);
        $this->assertSame($source, $project->app()->source());
    }

    public function test_application_exposes_git_and_files_collaborators(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $project = $this->dind($model);
        $app = $project->app();

        $this->assertInstanceOf(DindApp::class, $app);
        $this->assertInstanceOf(GitRepository::class, $app->source()->git());
        $this->assertInstanceOf(Files::class, $app->source()->files());
        $this->assertSame($app->source()->git(), $app->source()->git());
    }

    public function test_php_hosting_runtime_exposes_php_runtime_not_source(): void
    {
        $model = new ModelsUser();
        $model->username = 'bob';
        $model->setDetails(['template' => 'php']);
        $project = $this->phpHosting($model);

        $php = $project->phpRuntime();
        $this->assertFalse(method_exists($php, 'source'));
    }

    public function test_project_tree_refuses_to_clear_paths_outside_home_project(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $project = $this->dind($model);
        $tree = new ProjectTree($project);

        $this->expectException(\InvalidArgumentException::class);
        $tree->clearContents('/var/www/html');
    }

    public function test_git_repository_does_not_call_deploy_preparation(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Dind/Source/GitRepository.php');
        $this->assertStringNotContainsString('prepareUserAppFromSources', $source);
        $this->assertStringNotContainsString('SourcePreparation', $source);
        $this->assertStringNotContainsString('PrepareFromSource', $source);
        $this->assertStringNotContainsString('DetectProjectStrategy', $source);
    }

    public function test_clone_configured_repository_emits_deploy_log_lines_like_lib(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Dind/Source/GitRepository.php');
        $this->assertStringContainsString('Cloning repository', $source);
        $this->assertStringContainsString('Repository cloned', $source);
        $this->assertStringContainsString('Submodules fetched', $source);
        $this->assertStringContainsString('Some submodules could not be fetched, so parts of this repository are missing', $source);
        $this->assertStringContainsString('allowUntrustedGitDirectory', $source);
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function phpHosting(ModelsUser $model): PhpHosting
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));

        return $model;
    }

    private function system(): System
    {
        return new class ($this->tmpRoot, $this->homeRoot) extends System {
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
