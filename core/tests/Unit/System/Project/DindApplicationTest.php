<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\AbstractApplication;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication as DindApp;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

class DindApplicationTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-app-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_app_returns_null_without_deploy_strategy(): void
    {
        $project = $this->dind($this->dindModel());

        $this->assertNull($project->app());
    }

    public function test_app_returns_null_for_provisioned_placeholder_without_deploy(): void
    {
        $model = $this->dindModel();
        file_put_contents(
            $this->homeRoot . '/alice/project/docker-compose.yml',
            "services:\n  welcome:\n    image: nginx\n"
        );

        $project = $this->dind($model);

        $this->assertNull($project->app());
    }

    public function test_app_returns_application_when_deploy_strategy_is_frozen(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services: {}\n");

        $project = $this->dind($model);
        $app = $project->app();

        $this->assertInstanceOf(DindApp::class, $app);
        $this->assertInstanceOf(AbstractApplication::class, $app);
        $this->assertSame($app, $project->app());
    }

    public function test_application_exposes_shell_and_container_collaborators(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services: {}\n");
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");

        $project = $this->dind($model);
        $app = $project->app();

        $this->assertInstanceOf(ShellOperations::class, $app->shell());
        $this->assertInstanceOf(ContainerOperations::class, $app->containers());
        $this->assertSame($app->shell(), $app->shell());
    }

    public function test_shell_wrap_targets_outer_compose_dind_service(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $outerCompose = $this->tmpRoot . '/users/alice/docker-compose.yml';
        file_put_contents($outerCompose, "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services: {}\n");

        $project = $this->dind($model);
        $wrap = $project->app()->shell()->wrap(['echo', 'hi']);

        $this->assertSame(
            [
                'sudo',
                'docker',
                'compose',
                '-f',
                $outerCompose,
                'exec',
                '-T',
                'dind',
                'echo',
                'hi',
            ],
            $wrap
        );
    }

    public function test_container_operations_rejects_invalid_service_names(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services: {}\n");
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");

        $project = $this->dind($model);
        $containers = $project->app()->containers();

        $this->expectException(\InvalidArgumentException::class);
        $containers->getServiceLogs('bad service!');
    }

    public function test_user_app_compose_command_lists_inner_compose_files(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'compose']);
        $inner = $this->homeRoot . '/alice/project/compose.yaml';
        file_put_contents($inner, "services:\n  app:\n    image: test\n");
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");

        $project = $this->dind($model);

        $this->assertSame(
            [
                'env',
                'PWD=' . $this->homeRoot . '/alice/project',
                'docker',
                'compose',
                '--project-directory',
                $this->homeRoot . '/alice/project',
                '-f',
                $this->homeRoot . '/alice/project/docker-compose.panelalpha.yml',
                'ps',
            ],
            $project->userAppComposeCommand(['ps'])
        );
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

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

            public function filesystem(): Filesystem
            {
                return new Filesystem($this);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $parts = is_array($cmd) ? $cmd : [$cmd];
                if (count($parts) >= 4 && $parts[0] === 'sudo' && $parts[1] === 'test' && $parts[2] === '-f') {
                    $process = \Symfony\Component\Process\Process::fromShellCommandline(
                        is_file($parts[3]) ? 'true' : 'false'
                    );
                    $process->run();

                    return $process;
                }

                $process = new \Symfony\Component\Process\Process($parts);
                $process->run();

                return $process;
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
