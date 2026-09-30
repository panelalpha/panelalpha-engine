<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;

class PhpHostingRuntimeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-php-hosting-rt-' . bin2hex(random_bytes(4));
        $this->seedMinimalTemplate($this->tmpRoot);
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_materialize_and_start_create_compose_and_bring_project_up(): void
    {
        $model = $this->userModel('alice');
        $system = $this->recordingSystem($this->tmpRoot);
        $project = $this->phpHosting($system, $model);

        $this->assertFalse($project->exists());

        $project->materialize();
        $project->start();
        $project->awaitReady();

        $this->assertTrue($project->exists());
        $this->assertFileExists($project->composeFilePath());
        $this->assertContains('compose-up', $system->journal);
        $this->assertSame('php-hosting', $project->kind());
    }

    public function test_prepare_user_app_from_sources_is_noop_for_php_hosting(): void
    {
        $model = $this->userModel('dave');
        $system = $this->recordingSystem($this->tmpRoot);
        $aggregate = new ProjectAggregate($system, $model);

        $this->assertInstanceOf(PhpHosting::class, $aggregate->runtime());

        $aggregate->prepareUserAppFromSources();

        $this->assertSame([], $system->journal);
    }

    public function test_remove_tears_down_compose_without_linux_home(): void
    {
        $model = $this->userModel('bob');
        $system = $this->recordingSystem($this->tmpRoot);
        $project = $this->phpHosting($system, $model);

        $home = $system->projectHomeDirPath('bob');
        $projectDir = $system->projectDirPath('bob');
        mkdir($home, 0777, true);
        mkdir($projectDir, 0777, true);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $project->remove();

        $this->assertContains('compose-down', $system->journal);
        $this->assertTrue(is_dir($home));
    }

    public function test_start_stop_and_remove_outer_lifecycle(): void
    {
        $model = $this->userModel('carol');
        $system = $this->recordingSystem($this->tmpRoot);
        $project = $this->phpHosting($system, $model);

        $projectDir = $system->projectDirPath('carol');
        mkdir($projectDir, 0777, true);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $project->start();
        $this->assertContains('compose-up', $system->journal);

        $project->stop();
        $this->assertContains('compose-down', $system->journal);

        $project->remove();
        $this->assertContains('compose-down', $system->journal);
    }

    private function phpHosting(System $system, ModelsUser $model): PhpHosting
    {
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function userModel(string $username): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails([
            'template' => 'default',
            'UID' => 1001,
            'GID' => 1001,
            'cpu_limit' => 1.0,
            'memory_limit' => 512,
        ]);

        return $model;
    }

    private function recordingSystem(string $engineRoot, bool $failOnComposeUp = false): System
    {
        return new class ($engineRoot, $failOnComposeUp) extends System {
            /** @var list<string> */
            public array $journal = [];

            public function __construct(
                private string $engineRoot,
                private bool $failOnComposeUp,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'docker compose') && str_contains($line, ' up ')) {
                    $this->journal[] = 'compose-up';
                    if ($this->failOnComposeUp) {
                        throw new \Exception('compose up failed');
                    }
                }
                if (str_contains($line, 'docker compose') && str_contains($line, ' down')) {
                    $this->journal[] = 'compose-down';
                }
                if (str_contains($line, 'mkdir')) {
                    $this->simulateMkdir($line);
                }
                if (str_contains($line, 'sudo cp ')) {
                    $parts = preg_split('/\s+/', $line);
                    $source = $parts[2] ?? null;
                    $target = $parts[3] ?? null;
                    if (is_string($source) && is_string($target)) {
                        if (!is_dir(dirname($target))) {
                            mkdir(dirname($target), 0777, true);
                        }
                        if (is_file($source)) {
                            copy($source, $target);
                        } elseif (is_dir($source)) {
                            if (!is_dir($target)) {
                                mkdir($target, 0777, true);
                            }
                        }
                    }
                }
                if (str_contains($line, 'docker image inspect')) {
                    // treat image as present so buildIfMissing is a no-op pull path
                    return '';
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'docker compose') && str_contains($line, ' down')) {
                    $this->journal[] = 'compose-down';
                }

                return \Tests\Support\FakeProcess::ok();
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                return $this->runProcess($cmd, $env);
            }

            private function simulateMkdir(string $line): void
            {
                if (!preg_match('/mkdir -p (.+)$/', $line, $m)) {
                    return;
                }
                $path = trim($m[1]);
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }
        };
    }

    private function seedMinimalTemplate(string $engineRoot): void
    {
        $projectTemplate = $engineRoot . '/templates/user/default/project';
        mkdir($projectTemplate . '/entrypoint-init.d', 0777, true);
        mkdir($projectTemplate . '/crontabs', 0777, true);
        touch($projectTemplate . '/crontabs/www-data');
        file_put_contents($projectTemplate . '/php/versions-available', "8.3\n");
        mkdir($projectTemplate . '/php/8.3/fpm/pool.d', 0777, true);
        file_put_contents($projectTemplate . '/php/8.3/fpm/pool.d/www.conf', "; pool\n");
        file_put_contents($projectTemplate . '/Dockerfile-fpm', 'FROM scratch');
        file_put_contents($projectTemplate . '/docker-compose.yml-fpm', "services:\n  php:\n    image: test\n");
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        $property = (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver');
        $property->setAccessible(true);
        $property->setValue(null, $webserver);
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
