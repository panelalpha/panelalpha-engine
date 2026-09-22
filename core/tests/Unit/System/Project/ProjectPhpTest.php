<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Php;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;

class ProjectPhpTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-php-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_project_php_returns_collaborator_with_ini_path_under_project_dir(): void
    {
        $system = $this->system();
        $project = new Project($system, $this->phpHostingModel());

        $php = $project->php();

        $this->assertInstanceOf(Php::class, $php);
        $this->assertSame(
            $system->projectDirPath('alice') . '/php/8.3/custom.ini',
            $php->customIniFilePath('8.3')
        );
    }

    public function test_get_and_update_custom_ini_settings_on_php_hosting(): void
    {
        $system = $this->system();
        $projectDir = $system->projectDirPath('alice');
        mkdir("{$projectDir}/php/8.3", 0777, true);
        file_put_contents("{$projectDir}/php/8.3/custom.ini", "display_errors=0\n");

        $project = new Project($system, $this->phpHostingModel());
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $php = $project->php();

        $this->assertSame(['display_errors' => '0'], $php->getCustomIniSettings('8.3'));

        $php->updateCustomIniSettings('8.3', ['display_errors' => '1', 'log_errors' => '1']);

        $this->assertSame(
            ['display_errors' => '1', 'log_errors' => '1'],
            $php->getCustomIniSettings('8.3')
        );
        $this->assertCount(1, $system->processJournal);
        $this->assertSame(
            [
                'sudo',
                'docker',
                'compose',
                '-f',
                $project->composeFilePath(),
                'exec',
                '-T',
                'php',
                'bash',
                '/entrypoint-runner.sh',
                'restart',
                'php-fpm8.3',
            ],
            $system->processJournal[0]
        );
    }

    public function test_update_on_dind_writes_ini_without_compose_exec(): void
    {
        $system = $this->system();
        $projectDir = $system->projectDirPath('alice');
        mkdir("{$projectDir}/php/8.2", 0777, true);
        file_put_contents("{$projectDir}/php/8.2/custom.ini", "display_errors=0\n");

        $project = new Project($system, $this->dindModel());
        $php = $project->php();

        $php->updateCustomIniSettings('8.2', ['display_errors' => '1']);

        $this->assertSame(['display_errors' => '1'], $php->getCustomIniSettings('8.2'));
        $this->assertSame([], $system->processJournal);
    }

    public function test_invalid_ini_settings_are_rejected(): void
    {
        $system = $this->system();
        $projectDir = $system->projectDirPath('alice');
        mkdir("{$projectDir}/php/8.3", 0777, true);
        $iniPath = "{$projectDir}/php/8.3/custom.ini";
        file_put_contents($iniPath, "display_errors=0\n");

        $project = new Project($system, $this->phpHostingModel());

        $thrown = null;
        try {
            $project->php()->updateCustomIniSettings('8.3', ['foo' => "\"unclosed"]);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\InvalidArgumentException::class, $thrown);
        $this->assertSame("display_errors=0\n", file_get_contents($iniPath));
        $this->assertSame([], $system->processJournal);
    }

    private function phpHostingModel(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'default',
            'UID' => 1001,
            'GID' => 1001,
            'memory_limit' => 512,
        ]);

        return $model;
    }

    private function dindModel(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1001,
            'GID' => 1001,
        ]);

        return $model;
    }

    private function system(): System
    {
        return new class ($this->tmpRoot) extends System {
            /** @var list<array<int, string>> */
            public array $processJournal = [];

            public function __construct(
                private string $engineRoot,
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                if (is_array($cmd)) {
                    $this->processJournal[] = $cmd;
                }

                return new class () extends \Symfony\Component\Process\Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }
        };
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
