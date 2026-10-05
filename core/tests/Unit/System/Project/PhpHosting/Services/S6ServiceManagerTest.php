<?php

namespace Tests\Unit\System\Project\PhpHosting\Services;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmApacheStack;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\LiteSpeedStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Project\PhpHosting\Services\S6ServiceManager;
use App\System\Project\PhpHosting\Services\Service;
use App\System\Services\Webserver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class S6ServiceManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-php-s6-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/users/alice', 0777, true);
        // The real template, so its own services are what render() must keep.
        symlink(dirname(__DIR__, 7) . '/templates', $this->root . '/templates');
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_the_template_ships_redis_and_cron_as_services(): void
    {
        $dir = $this->root . '/templates/user/default/project/services';

        foreach (['redis', 'cron'] as $service) {
            $this->assertFileExists("{$dir}/{$service}/run");
            $this->assertTrue(is_executable("{$dir}/{$service}/run"), "{$service}/run must be executable for s6");
        }
        $this->assertFileDoesNotExist($this->root . '/templates/user/default/project/entrypoint-runner.sh');
    }

    public function test_an_account_rendered_before_s6_keeps_the_runner(): void
    {
        $project = $this->project();
        $this->assertFalse(S6ServiceManager::manages($project));
        $this->assertInstanceOf(RunnerServiceManager::class, $project->services());

        mkdir($this->projectDir() . '/services');
        $this->assertTrue(S6ServiceManager::manages($project));
        $this->assertInstanceOf(S6ServiceManager::class, $project->services());
    }

    public function test_handlers_become_services_and_the_templates_own_stay(): void
    {
        $dir = $this->projectDir();
        foreach (['redis', 'cron', 'php-fpm7.4'] as $service) {
            mkdir("{$dir}/services/{$service}", 0777, true);
            file_put_contents("{$dir}/services/{$service}/run", "#!/bin/sh\n");
        }
        mkdir("{$dir}/entrypoint.d");
        file_put_contents("{$dir}/entrypoint.d/php-fpm7.4.sh", "exec php-fpm7.4 -F\n");
        file_put_contents("{$dir}/entrypoint-runner.sh", "#!/bin/bash\n");

        (new S6ServiceManager($this->project()))->write([new Service('php-fpm8.4', 'exec php-fpm8.4 -F')]);

        $this->assertSame("#!/bin/bash\necho \$\$ > sid\nexec php-fpm8.4 -F\n", file_get_contents("{$dir}/services/php-fpm8.4/run"));
        $this->assertSame('0755', substr(sprintf('%o', fileperms("{$dir}/services/php-fpm8.4/run")), -4));
        // Workers a master left behind would hold its port: finish kills the session.
        $finish = (string) file_get_contents("{$dir}/services/php-fpm8.4/finish");
        $this->assertStringContainsString('[ "$4" = "$sid" ] && kill -9', $finish);
        exec('sh -n -c ' . escapeshellarg($finish) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame('0755', substr(sprintf('%o', fileperms("{$dir}/services/php-fpm8.4/finish")), -4));
        $this->assertDirectoryDoesNotExist("{$dir}/services/php-fpm7.4", 'a version no domain uses any more is dropped');
        $this->assertDirectoryExists("{$dir}/services/redis");
        $this->assertDirectoryExists("{$dir}/services/cron");
        $this->assertDirectoryDoesNotExist("{$dir}/entrypoint.d");
        $this->assertFileDoesNotExist("{$dir}/entrypoint-runner.sh");
    }

    /** A master killed with -9 leaves its workers behind, in the session s6 gave the service. */
    public function test_finish_kills_what_is_left_of_the_session(): void
    {
        $dir = $this->projectDir();
        (new S6ServiceManager($this->project()))->write([new Service('php-fpm8.4', 'exec php-fpm8.4 -F')]);
        $finish = "{$dir}/services/php-fpm8.4/finish";

        $leader = new Process(['setsid', 'sh', '-c', 'echo $$ > sid; sleep 60 >/dev/null 2>&1 & echo $! > orphan'], $dir);
        $leader->run();
        $outsider = new Process(['sleep', '60']);
        $outsider->start();
        $orphan = (int) file_get_contents("{$dir}/orphan");
        $this->assertTrue($this->alive($orphan));

        (new Process(['sh', $finish], $dir))->run();

        $this->assertFalse($this->alive($orphan), 'the worker the leader left is killed');
        $this->assertTrue($outsider->isRunning(), 'nothing outside the session is touched');
        $this->assertFileDoesNotExist("{$dir}/sid");
        $outsider->stop(0);
    }

    public function test_apache_runs_in_the_foreground_as_a_service(): void
    {
        $project = $this->project();
        $stack = new FpmApacheStack($project->system(), $project->userModel());
        mkdir($this->projectDir() . '/services');
        $services = new S6ServiceManager($project);

        $this->assertSame([], $services->bootScripts($stack->services($project)));
        $this->assertArrayNotHasKey('20-apache.sh', $stack->entrypointInitScripts($project));
        $services->write($stack->services($project));

        $run = (string) file_get_contents($this->projectDir() . '/services/apache2/run');
        $this->assertStringContainsString('. /etc/apache2/envvars', $run);
        $this->assertStringEndsWith("exec apache2 -DFOREGROUND\n", $run);
        $this->assertDirectoryDoesNotExist($this->projectDir() . '/entrypoint.d');
    }

    public function test_restart_and_cron_reload_go_through_s6_once_the_account_is_on_it(): void
    {
        $system = $this->system();
        $project = $this->project($system);
        file_put_contents($project->composeFilePath(), "services:\n  php:\n    image: test\n");
        mkdir($this->projectDir() . '/services');
        $system->journal = [];

        $project->phpRuntime()->restartPhpHandler('8.4');
        $project->reloadCron();
        $project->syncServices();

        $prefix = ['docker', 'compose', '-f', $project->composeFilePath(), 'exec', '-T', 'php'];
        $this->assertSame([...$prefix, 'bash', '-c', FpmStack::restartScript(new S6ServiceManager($project), '8.4')], $system->journal[0]);
        $this->assertSame([...$prefix, 's6-svc', '-r', '/run/service/cron'], $system->journal[1]);
        $this->assertSame([...$prefix, 'sh', '-c', S6ServiceManager::syncScript()], $system->journal[2]);
        $this->assertStringNotContainsString('entrypoint-runner', implode("\n", array_merge(...$system->journal)));
    }

    public function test_lsphp_restarts_through_s6_and_nothing_stray_is_looked_for(): void
    {
        $system = $this->system();
        $project = $this->project($system);
        mkdir($this->projectDir() . '/services');
        $system->journal = [];

        (new LiteSpeedStack($system, $project->userModel()))->restartPhpHandler($project, '8.4');

        $prefix = ['docker', 'compose', '-f', $project->composeFilePath(), 'exec', '-T', 'php'];
        $this->assertSame([[...$prefix, 'sh', '-c', S6ServiceManager::restartScriptFor('lsphp84')]], $system->journal);
        // s6 starts nothing it does not supervise, so the main process pattern goes unused.
        $this->assertSame(
            S6ServiceManager::restartScriptFor('php-fpm8.4'),
            (new S6ServiceManager($project))->restartScript('php-fpm8.4', '^php-fpm: master')
        );
        $this->assertSame([], (new S6ServiceManager($project))->bootScripts([new Service('a', 'b', ['x.sh' => 'y'])]));
    }

    public function test_scripts_are_valid_sh(): void
    {
        $fpm = FpmStack::restartScript(new S6ServiceManager($this->project()), '8.4');
        foreach ([S6ServiceManager::syncScript(), S6ServiceManager::restartScriptFor('lsphp84'), $fpm] as $script) {
            exec('sh -n -c ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    public function test_restart_reports_a_service_that_is_not_there_before_touching_anything(): void
    {
        $this->assertStringStartsWith(
            '[ -f /etc/s6/account/php-fpm8.3/run ] || exit ' . FpmStack::EXIT_NOT_MANAGED,
            FpmStack::restartScript(new S6ServiceManager($this->project()), '8.3')
        );
    }

    /** s6-svscan keeps its own state in .s6-svscan; only the dirs sync retired may be removed. */
    public function test_sync_removes_only_what_it_retired(): void
    {
        $script = S6ServiceManager::syncScript();

        $this->assertStringContainsString('mv "$d" /run/service/.retired-$n-$$', $script);
        $this->assertStringContainsString('for d in /run/service/.retired-*/; do', $script);
        $this->assertStringNotContainsString('/run/service/.*', $script);
    }

    public function test_a_name_that_is_not_a_plain_word_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        S6ServiceManager::restartScriptFor('x; rm -rf /');
    }

    private function alive(int $pid): bool
    {
        usleep(200000);
        $stat = @file_get_contents("/proc/{$pid}/stat");

        // A zombie has nobody to reap it here, and is as dead as it gets.
        return $stat !== false && !str_contains(substr($stat, strrpos($stat, ')') + 1, 3), 'Z');
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver')->setValue(null, $webserver);
    }

    private function projectDir(): string
    {
        return $this->root . '/users/alice';
    }

    private function project(?System $system = null): PhpHosting
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $model->setRelation('domains', new \Illuminate\Database\Eloquent\Collection());
        $runtime = (new ProjectAggregate($system ?? $this->system(), $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    /** Runs file commands for real, without sudo; records and skips docker. */
    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<list<string>> */
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
                $argv = is_array($cmd) ? array_values($cmd) : ['sh', '-c', $cmd];
                if ($argv[0] === 'sudo') {
                    array_shift($argv);
                }
                if ($argv[0] === 'docker') {
                    $this->journal[] = $argv;
                    $argv = ['true'];
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
