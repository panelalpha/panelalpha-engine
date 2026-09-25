<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DindInnerRuntimeTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-inner-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_dind_exposes_inner_runtime_collaborators(): void
    {
        $project = $this->dind($this->dindModel(['deploy_strategy' => 'express']));

        $this->assertInstanceOf(ShellOperations::class, $project->shell());
        $this->assertInstanceOf(ProjectFiles::class, $project->projectTree());
        $this->assertInstanceOf(InnerDocker::class, $project->innerDocker());
        $this->assertInstanceOf(HostCompile::class, $project->hostCompile());
        $this->assertInstanceOf(ComposeWriter::class, $project->composeWriter());
        $this->assertInstanceOf(Networking::class, $project->networking());
        $this->assertSame($project->innerDocker(), $project->innerDocker());
    }

    public function test_compose_writer_writes_inner_compose_file(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $project = $this->dind($model);
        $yaml = "services:\n  app:\n    image: nginx:alpine\n";

        $project->composeWriter()->writeGeneratedCompose($project->userAppDirPath(), $yaml, null);

        $composeFile = $this->homeRoot . '/alice/project/' . EngineArtifacts::RUN_COMPOSE;
        $this->assertSame($composeFile, $project->userAppComposeFilePath());
        $this->assertFileExists($composeFile);
        $this->assertStringContainsString('nginx:alpine', file_get_contents($composeFile));
        $this->assertSame('nginx:alpine', $model->getDetails()['deploy_image'] ?? null);
    }

    public function test_networking_public_app_url_uses_main_domain_ssl_flag(): void
    {
        $domain = new DomainModel();
        $domain->domain = 'app.example.test';
        $domain->setDetails(['ssl_disabled' => true]);

        $user = $this->getMockBuilder(ModelsUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMainDomain'])
            ->getMock();
        $user->forceFill(['username' => 'alice']);
        $user->setDetails(['template' => 'dind']);
        $user->method('getMainDomain')->willReturn($domain);

        $project = $this->dind($user);

        $this->assertSame('http://app.example.test', $project->publicAppUrl());
    }

    public function test_inner_docker_declared_image_ports_is_safe_on_missing_image(): void
    {
        $project = $this->dind($this->dindModel(['deploy_strategy' => 'php']));

        $this->assertSame([], $project->innerDocker()->declaredImagePorts('not a valid ref!!!'));
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        // No database in unit tests: deploy snapshots stay on the model.
        $model = self::getStubBuilder(ModelsUser::class)->onlyMethods(['save'])->getStub();
        $model->method('save')->willReturn(true);
        $model->username = 'alice';
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;

        return new class ($tmpRoot, $homeRoot) extends System {
            public function __construct(
                private string $engineRoot,
                private string $homeRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->homeRoot);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homeRoot . '/' . $username;
            }

            // Filesystem probes answer from the temp tree, without sudo.
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = $this->withoutSudo($cmd);
                if (($argv[0] ?? null) !== 'test') {
                    throw new \LogicException('Unexpected process in test: ' . implode(' ', $argv));
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }

            // File writes really happen so the test can assert on them; anything else is inert.
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = $this->withoutSudo($cmd);
                if (in_array($argv[0] ?? null, ['mkdir', 'cp', 'chmod'], true)) {
                    return (new Process($argv))->mustRun()->getOutput();
                }

                return '';
            }

            private function withoutSudo(string|array $cmd): array
            {
                $argv = is_array($cmd) ? array_values($cmd) : explode(' ', $cmd);

                return ($argv[0] ?? null) === 'sudo' ? array_slice($argv, 1) : $argv;
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
