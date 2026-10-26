<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\App as DindApp;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Services\Webserver;
use Mockery;
use PHPUnit\Framework\TestCase;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;

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
        Mockery::close();
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
        $journal = $system->processJournal;
        $this->assertNotEmpty($journal);
        $this->assertStringContainsString('php-fpm8.3', implode(' ', end($journal)));
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
                FpmStack::restartScript(new RunnerServiceManager($project), '8.1'),
            ],
            $system->processJournal[0]
        );
    }

    public function test_run_wp_cli_builds_compose_exec_command(): void
    {
        $system = $this->system();
        $user = $this->userModel();
        $user->shouldReceive('getMainDomain')->andReturn(
            $this->domain('example.test', '/example.test/public_html', '8.3')
        );
        $project = $this->phpHosting($user, $system);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");

        $result = $project->phpRuntime()->runWpCli(['plugin', 'list']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('wp-out', $result['stdout']);
        $command = $system->processJournal[0];
        $this->assertSame('/usr/bin/php8.3', $command[10]);
        $this->assertSame('-d', $command[11]);
        $this->assertSame('memory_limit=384M', $command[12]);
        $this->assertSame('/opt/wp-cli.phar', $command[13]);
        $this->assertSame('--exec='.MustUsePlugin::MEND_ON_LOAD, $command[14], 'an old must-use plugin is mended first');
        $this->assertSame('plugin', $command[15]);
        $this->assertSame('list', $command[16]);
    }

    /** A --path that names a subdirectory of a document root still picks that domain's PHP. */
    public function test_wp_cli_binary_follows_a_domain_matched_by_a_subdirectory_path(): void
    {
        $path = '/home/alice/example.test/public_html/wp-content';
        $domain = $this->domain('example.test', '/example.test/public_html', '8.1');
        $project = $this->phpHosting($this->userModelForPath($path, $domain));

        $this->assertSame('/usr/bin/php8.1', $project->phpRuntime()->phpBinaryPath($path));
    }

    public function test_wp_cli_binary_follows_a_domain_matched_by_a_trailing_slash(): void
    {
        $path = '/home/alice/example.test/public_html/';
        $domain = $this->domain('example.test', '/example.test/public_html', '7.4');
        $project = $this->phpHosting($this->userModelForPath($path, $domain));

        $this->assertSame('/usr/bin/php7.4', $project->phpRuntime()->phpBinaryPath($path));
    }

    public function test_wp_cli_binary_follows_a_domain_matched_by_a_relative_path(): void
    {
        $path = 'example.test/public_html';
        $domain = $this->domain('example.test', '/example.test/public_html', '8.2');
        $project = $this->phpHosting($this->userModelForPath($path, $domain));

        $this->assertSame('/usr/bin/php8.2', $project->phpRuntime()->phpBinaryPath($path));
    }

    /** With no path, WP-CLI runs the account's main-domain PHP, not a hard-coded minor. */
    public function test_wp_cli_binary_without_a_path_uses_the_main_domain_version(): void
    {
        $user = $this->userModel();
        $user->shouldReceive('getMainDomain')->andReturn(
            $this->domain('example.test', '/example.test/public_html', '7.4')
        );
        $project = $this->phpHosting($user);

        $this->assertSame('/usr/bin/php7.4', $project->phpRuntime()->phpBinaryPath());
    }

    /** The version comes from whatever domain the lookup returns; the prefix rule is the model's. */
    public function test_wp_cli_binary_uses_the_version_of_the_domain_the_lookup_returns(): void
    {
        $path = '/home/alice/example.test/shop/public';
        $domain = $this->domain('shop.example.test', '/example.test/shop', '8.0');
        $project = $this->phpHosting($this->userModelForPath($path, $domain));

        $this->assertSame('/usr/bin/php8.0', $project->phpRuntime()->phpBinaryPath($path));
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
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'default',
            'UID' => 1001,
            'GID' => 1001,
            'memory_limit' => 512,
        ]);

        return $model;
    }

    private function userModelForPath(string $path, Domain $domain): ModelsUser
    {
        $model = $this->userModel();
        $model->shouldReceive('findDomainByDocumentRoot')->with($path)->andReturn($domain);

        return $model;
    }

    private function domain(string $name, string $documentRoot, string $phpVersion): Domain
    {
        $domain = new Domain();
        $domain->domain = $name;
        $domain->type = 'main';
        $domain->setDetails(['document_root' => $documentRoot, 'php_version' => $phpVersion]);

        return $domain;
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
