<?php

namespace Tests\Unit\System\Project;

use Tests\Support\FakeProcess;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\AppCertificate;
use Tests\TestCase;

class DindApplicationOperationsTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    /** @var list<string|list<string>> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-app-ops-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_abort_running_deploy_targets_inner_compose_not_outer_delete(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $outerCompose = $this->tmpRoot . '/users/alice/docker-compose.yml';
        file_put_contents($outerCompose, "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $project = $this->dind($model);
        $project->app()->abortRunningDeploy();

        $flat = $this->flattenExecuted();
        $this->assertStringContainsString('down', $flat);
        $this->assertStringContainsString('remove-orphans', $flat);
        $this->assertStringNotContainsString('docker rm -f alice', $flat);
    }

    public function test_start_application_does_not_delete_project(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $aggregate = new ProjectAggregate($this->recordingSystem(), $model);
        $runtime = $aggregate->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        $runtime->app()->start();

        $flat = $this->flattenExecuted();
        $this->assertStringContainsString('up', $flat);
        $this->assertStringNotContainsString('docker rm -f alice', $flat);
        $this->assertFileExists($runtime->composeFilePath());
    }

    public function test_project_abort_delegates_without_outer_stack_removal(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $aggregate = new ProjectAggregate($this->recordingSystem(), $model);
        $aggregate->abortRunningDeploy();

        $flat = $this->flattenExecuted();
        $this->assertStringContainsString('down', $flat);
        $this->assertStringNotContainsString('docker rm -f alice', $flat);
    }

    public function test_deprovision_runs_inner_teardown_then_outer_remove(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $aggregate = new class ($this->recordingSystem(), $model) extends ProjectAggregate {
            public function tearDownLinuxIsolation(): void
            {
                // Isolation teardown is not under test here.
            }
        };

        $aggregate->deprovision();

        $flat = $this->flattenExecuted();
        $downPos = strpos($flat, 'down');
        $rmPos = strpos($flat, 'docker rm -f alice');
        $this->assertNotFalse($downPos);
        $this->assertNotFalse($rmPos);
        $this->assertLessThan($rmPos, $downPos);
    }

    public function test_app_certificate_remember_records_missing_ssl_when_no_certificate(): void
    {
        $domain = new DomainModel();
        $domain->domain = 'app.example.test';
        $domain->ssl = true;

        $model = new class extends ModelsUser {
            /** @var list<array<string, mixed>> */
            public array $savedDetails = [];

            public ?DomainModel $mainDomain = null;

            public function save(array $options = []): bool
            {
                $this->savedDetails[] = $this->getDetails();

                return true;
            }

            // getMainDomain() queries the database; hand the fixture back instead.
            public function getMainDomain(): ?DomainModel
            {
                return $this->mainDomain;
            }
        };
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'deploy_strategy' => 'static']);
        $model->setRelation('domains', collect([$domain]));
        $model->mainDomain = $domain;

        $runtime = $this->dind($model);
        $runtime->appCertificate()->remember();

        $this->assertNotEmpty($model->savedDetails);
        $last = end($model->savedDetails);
        $this->assertSame('missing', $last[AppCertificate::DETAIL]['status'] ?? null);
        $this->assertSame('app.example.test', $last[AppCertificate::DETAIL]['domain'] ?? null);
    }

    public function test_run_ssh_command_returns_process_exit_code(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services: {}\n");

        $system = new class ($this->tmpRoot, $this->homeRoot) extends System {
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                return FakeProcess::ok();
            }
        };

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);
        $result = $runtime->app()->runSshCommand('echo hi');

        $this->assertSame(0, $result['exit_code']);
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->recordingSystem(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function dindModel(array $details = []): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));

        return $model;
    }

    private function recordingSystem(): System
    {
        $executed = &$this->executed;

        return new class ($this->tmpRoot, $this->homeRoot, $executed) extends System {
            /** @param list<string|list<string>> $executed */
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private array &$executed,
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

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->executed[] = $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $this->executed[] = $cmd;
                // Answer `test -e` from the fixture tree, so an absent certificate reads as absent.
                if (is_array($cmd) && ($cmd[0] ?? null) === 'test' && ($cmd[1] ?? null) === '-e') {
                    return file_exists((string) ($cmd[2] ?? '')) ? FakeProcess::ok() : FakeProcess::failed();
                }

                return FakeProcess::forCommand($cmd);
            }

            public function filesystem(): Filesystem
            {
                return new Filesystem($this);
            }
        };
    }

    private function flattenExecuted(): string
    {
        $parts = [];
        foreach ($this->executed as $cmd) {
            $parts[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
        }

        return implode("\n", $parts);
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
