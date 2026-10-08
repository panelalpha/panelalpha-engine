<?php

namespace Tests\Unit\System\Project\PhpHosting\Services;

use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmApacheStack;
use App\System\Project\PhpHosting\LiteSpeedStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Project\PhpHosting\Services\Service;
use App\System\Project\PhpHosting\Services\ServiceManager;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RunnerServiceManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-php-runner-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/users/alice', 0777, true);
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_services_are_runner_scripts_and_the_old_ones_go(): void
    {
        $dir = $this->root . '/users/alice/entrypoint.d';
        mkdir($dir);
        file_put_contents("{$dir}/php-fpm7.4.sh", "exec php-fpm7.4 -F\n");

        (new RunnerServiceManager($this->project()))->write([new Service('php-fpm8.4', 'exec php-fpm8.4 -F')]);

        $this->assertSame(['php-fpm8.4.sh'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        $this->assertSame('exec php-fpm8.4 -F', file_get_contents("{$dir}/php-fpm8.4.sh"));
    }

    /** These accounts never ran Apache under the runner: apache2ctl started it once at boot. */
    public function test_a_service_with_a_daemon_start_is_started_at_boot_not_by_the_runner(): void
    {
        $project = $this->project([$this->domain('8.4')]);
        $stack = new FpmApacheStack($project->system(), $project->userModel());
        $services = $project->services();
        $this->assertInstanceOf(RunnerServiceManager::class, $services);

        $this->assertSame(['20-apache.sh' => 'apache2ctl start'], $services->bootScripts($stack->services($project)));

        $services->write($stack->services($project));
        $dir = $this->root . '/users/alice/entrypoint.d';
        $this->assertSame(['php-fpm8.4.sh'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
    }

    public function test_sync_cron_reload_and_lsphp_restart_go_through_the_runner(): void
    {
        $system = $this->system();
        $project = $this->project([], $system);

        $project->syncServices();
        $project->reloadCron();
        (new LiteSpeedStack($system, $project->userModel()))->restartPhpHandler($project, '8.4');

        $compose = $project->composeFilePath();
        $this->assertSame([
            "sudo docker compose -f {$compose} exec -T php bash /entrypoint-runner.sh sync --all",
            "sudo docker compose -f {$compose} exec -T php service cron restart",
            ['sudo', 'docker', 'compose', '-f', $compose, 'exec', '-T', 'php', 'bash', '/entrypoint-runner.sh', 'restart', 'lsphp84'],
        ], $system->journal);
    }

    public function test_restart_script_reports_a_service_it_does_not_have_first(): void
    {
        $script = (new RunnerServiceManager($this->project()))->restartScript('php-fpm8.3', '^x');

        $this->assertStringStartsWith(
            '[ -f /entrypoint.d/php-fpm8.3.sh ] || exit ' . ServiceManager::EXIT_NOT_MANAGED . "\n",
            $script
        );
        $this->assertStringEndsWith('bash /entrypoint-runner.sh start php-fpm8.3 >/dev/null', $script);
        exec('bash -n -c ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /** A copy of the service the runner did not start is the only thing named on stdout. */
    public function test_restart_script_stops_a_stray_copy_between_stop_and_start(): void
    {
        $script = (new RunnerServiceManager($this->project()))->restartScript('php-fpm8.4', '^php-fpm: master');

        $stop = strpos($script, 'entrypoint-runner.sh stop php-fpm8.4 >/dev/null');
        $kill = strpos($script, "pkill -QUIT -f '^php-fpm: master'");
        $start = strpos($script, 'entrypoint-runner.sh start php-fpm8.4 >/dev/null');
        $this->assertNotFalse($stop);
        $this->assertNotFalse($kill);
        $this->assertNotFalse($start);
        $this->assertLessThan($kill, $stop);
        $this->assertLessThan($start, $kill);
        $this->assertStringContainsString('echo "stopped a php-fpm8.4 master the runner did not start: $stray"', $script);
    }

    public function test_layout_removal_drops_the_runner_and_its_scripts(): void
    {
        $dir = $this->root . '/users/alice';
        mkdir("{$dir}/entrypoint.d");
        file_put_contents("{$dir}/entrypoint.d/php-fpm8.4.sh", 'exec php-fpm8.4 -F');
        file_put_contents("{$dir}/entrypoint-runner.sh", "#!/bin/bash\n");

        RunnerServiceManager::removeLayout($this->project());

        $this->assertDirectoryDoesNotExist("{$dir}/entrypoint.d");
        $this->assertFileDoesNotExist("{$dir}/entrypoint-runner.sh");
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver')->setValue(null, $webserver);
    }

    private function domain(string $phpVersion): Domain
    {
        $domain = new Domain();
        $domain->domain = 'example.test';
        $domain->setDetails(['php_version' => $phpVersion]);

        return $domain;
    }

    /**
     * @param list<Domain> $domains
     */
    private function project(array $domains = [], ?System $system = null): PhpHosting
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $model->setRelation('domains', new \Illuminate\Database\Eloquent\Collection($domains));
        $runtime = (new ProjectAggregate($system ?? $this->system(), $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    /** Runs file commands for real, without sudo; records docker commands and skips them. */
    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<string|list<string>> */
            public array $journal = [];

            public function __construct(private string $engineRoot)
            {
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
                return $this->runProcess($cmd, $env, $timeout)->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'docker')) {
                    $this->journal[] = $cmd;
                    $cmd = ['true'];
                }
                $process = is_array($cmd)
                    ? new Process(array_values(array_filter($cmd, static fn ($a, $i) => !($i === 0 && $a === 'sudo'), ARRAY_FILTER_USE_BOTH)))
                    : Process::fromShellCommandline(str_replace('sudo ', '', $cmd));
                $process->run();

                return $process;
            }
        };
    }
}
