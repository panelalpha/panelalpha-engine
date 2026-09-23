<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\App as DindApp;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;

class PhpHostingApplicationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-php-hosting-app-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_app_is_reached_via_php_runtime_not_false_application_contract(): void
    {
        $project = $this->phpHosting($this->userModel());

        $php = $project->phpRuntime();

        $this->assertSame($php, $project->phpRuntime());
        $this->assertFalse(method_exists($php, 'shell'));
        $this->assertFalse(method_exists($php, 'containers'));
    }

    public function test_dind_application_does_not_expose_wp_cli(): void
    {
        $this->assertFalse(method_exists(DindApp::class, 'runWpCli'));
    }

    public function test_get_and_update_custom_ini_settings(): void
    {
        $system = $this->system();
        $projectDir = $system->projectDirPath('alice');
        mkdir("{$projectDir}/php/8.3", 0777, true);
        file_put_contents("{$projectDir}/php/8.3/custom.ini", "display_errors=0\n");

        $project = $this->phpHosting($this->userModel(), $system);
        $app = $project->phpRuntime();

        $this->assertSame(['display_errors' => '0'], $app->getCustomIniSettings('8.3'));

        $app->updateCustomIniSettings('8.3', ['display_errors' => '1', 'log_errors' => '1']);

        $this->assertSame(
            ['display_errors' => '1', 'log_errors' => '1'],
            $app->getCustomIniSettings('8.3')
        );
        $this->assertNotEmpty($system->processJournal);
        $this->assertStringContainsString('php-fpm8.3', implode(' ', $system->processJournal[0]));
    }

    public function test_restart_php_handler_uses_fpm_entrypoint_runner(): void
    {
        $system = $this->system();
        $project = $this->phpHosting($this->userModel(), $system);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $project->phpRuntime()->restartPhpHandler('8.1');

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
                '-c',
                FpmStack::restartFpmScript('8.1'),
            ],
            $system->processJournal[0]
        );
    }

    public function test_run_wp_cli_builds_compose_exec_command(): void
    {
        $system = $this->system();
        $project = $this->phpHosting($this->userModel(), $system);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $result = $project->phpRuntime()->runWpCli(['plugin', 'list']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('wp-out', $result['stdout']);
        $command = $system->processJournal[0];
        $this->assertSame('/usr/bin/php8.3', $command[10]);
        $this->assertSame('-d', $command[11]);
        $this->assertSame('memory_limit=384M', $command[12]);
        $this->assertSame('/opt/wp-cli.phar', $command[13]);
        $this->assertSame('plugin', $command[14]);
        $this->assertSame('list', $command[15]);
    }

    public function test_php_runtime_does_not_expose_shell_or_containers(): void
    {
        $project = $this->phpHosting($this->userModel());
        $php = $project->phpRuntime();

        $this->assertFalse(method_exists($php, 'shell'));
        $this->assertFalse(method_exists($php, 'containers'));
    }

    private function phpHosting(ModelsUser $model, ?System $system = null): PhpHosting
    {
        $runtime = (new ProjectAggregate($system ?? $this->system(), $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function userModel(): ModelsUser
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

                    public function getOutput(): string
                    {
                        return 'wp-out';
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
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
