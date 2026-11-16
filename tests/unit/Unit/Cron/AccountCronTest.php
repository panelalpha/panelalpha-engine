<?php

namespace Tests\Unit\Cron;

use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountCron;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A container project's cron job runs in one of its services: the account's
 * cron calls the runner with the service and the command, and the runner
 * execs the command in that service's running container.
 */
class AccountCronTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-account-cron-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/bin', 0777, true);
        mkdir($this->root . '/home', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function commands(): array
    {
        return [
            'percent, which cron turns into a newline' => ['date +%Y-%m-%d >> /tmp/day'],
            'quotes and redirects' => ['echo "it\'s" > /app/x 2>&1'],
            'substitution' => ['php artisan schedule:run --env=$(cat /app/.env.name)'],
            'hash' => ['curl -fsS https://example.test/#frag'],
            'unicode' => ['echo zażółć'],
        ];
    }

    #[DataProvider('commands')]
    public function test_the_service_and_command_read_back_as_written(string $command): void
    {
        $stored = AccountCron::command('app', $command);

        $this->assertSame(['service' => 'app', 'command' => $command], AccountCron::parse($stored));
        $this->assertDoesNotMatchRegularExpression('/[%\'"$#>]/', $stored);
    }

    public function test_a_command_not_written_for_a_service_is_left_alone(): void
    {
        $this->assertNull(AccountCron::parse('php artisan schedule:run'));
        $this->assertNull(AccountCron::parse('/bin/sh ' . AccountCron::RUNNER . ' app not*base64'));
    }

    public function test_the_longest_job_fits_crons_command_limit(): void
    {
        $stored = AccountCron::command(str_repeat('s', 63), str_repeat('x', AccountCron::MAX_COMMAND_BYTES));

        $this->assertLessThan(1000, strlen($stored));
    }

    public function test_the_runner_execs_the_decoded_command_in_the_services_container(): void
    {
        $this->fakeDocker("abc123\n");

        $this->runRunner('app', 'echo "ran in $FAKE_CONTAINER" > ' . $this->root . '/out');

        $this->assertSame("ran in abc123\n", file_get_contents($this->root . '/out'));
        $this->assertSame("ps -q --filter label=com.docker.compose.service=app\n", file_get_contents($this->root . '/ps-args'));
        $log = $this->log();
        $this->assertStringContainsString('[app] start: echo', $log);
        $this->assertStringContainsString('[app] exit 0', $log);
    }

    public function test_a_service_that_is_not_running_is_skipped_and_logged(): void
    {
        $this->fakeDocker('');

        $this->runRunner('worker', 'touch ' . $this->root . '/out');

        $this->assertFileDoesNotExist($this->root . '/out');
        $this->assertStringContainsString('[worker] skipped: the service is not running', $this->log());
    }

    public function test_a_run_still_going_keeps_the_next_one_out(): void
    {
        $this->fakeDocker("abc123\n");
        $command = 'touch ' . $this->root . '/out';
        $lockDir = $this->root . '/home/.panelalpha/cron';
        mkdir($lockDir, 0777, true);
        $encoded = base64_encode($command);
        $lock = $lockDir . '/' . md5("app {$encoded}") . '.lock';
        $holder = proc_open(['flock', $lock, 'sleep', '5'], [], $pipes);
        usleep(300_000);

        try {
            $this->runRunner('app', $command);
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }

        $this->assertFileDoesNotExist($this->root . '/out');
        $this->assertStringContainsString('skipped: the previous run has not finished', $this->log());
    }

    public function test_the_install_script_puts_the_crontab_in_the_spool_as_the_account_user(): void
    {
        $script = $this->cron($this->dind(running: false))->installScript();

        $this->assertStringContainsString('install -o 1500 -g 1501 -m 0600 /entrypoint.d/crontab /var/spool/cron/crontabs/alice', $script);
        $this->assertStringContainsString('rm -f /var/spool/cron/crontabs/alice', $script);
        $this->assertSame(0, $this->syntaxCheck($script));
    }

    public function test_delivery_writes_the_files_and_installs_them_in_a_running_account(): void
    {
        $system = $this->system();

        $this->cron($this->dind(running: true, system: $system))->deliver("* * * * * echo a\n");

        $this->assertSame(
            [AccountCron::CRONTAB_FILE, AccountCron::INSTALL_FILE, AccountCron::RUNNER_FILE],
            array_map('basename', array_keys($system->writes))
        );
        $this->assertSame(['/engine/users/alice/entrypoint.d'], array_values(array_unique(array_map('dirname', array_keys($system->writes)))));
        $this->assertSame("* * * * * echo a\n", $system->writes['/engine/users/alice/entrypoint.d/' . AccountCron::CRONTAB_FILE]);
        $this->assertSame('644', $system->modes['/engine/users/alice/entrypoint.d/' . AccountCron::RUNNER_FILE]);
        $this->assertCount(1, $system->calls);
        $this->assertSame(['sh', '/entrypoint.d/' . AccountCron::INSTALL_FILE], array_slice($system->calls[0], -2));
    }

    public function test_a_stopped_account_gets_the_files_and_installs_them_when_it_boots(): void
    {
        $system = $this->system();

        $this->cron($this->dind(running: false, system: $system))->deliver('');

        $this->assertCount(3, $system->writes);
        $this->assertSame([], $system->calls);
    }

    /** An account rendered again, or created, gets them beside its init scripts. */
    public function test_the_account_init_scripts_bring_the_cron_scripts(): void
    {
        $system = $this->system();
        $services = $this->createStub(ServiceManager::class);
        $dind = $this->getMockBuilder(Dind::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['system', 'projectDirPath', 'userModel', 'services'])
            ->getMock();
        $dind->expects($this->atLeastOnce())->method('system')->willReturn($system);
        $dind->method('projectDirPath')->willReturn('/engine/users/alice');
        $dind->method('userModel')->willReturn($this->dind(running: false)->userModel());
        $dind->method('services')->willReturn($services);

        $dind->setupEntrypointInitScripts();

        $runner = '/engine/users/alice/entrypoint.d/' . AccountCron::RUNNER_FILE;
        $this->assertSame(AccountCron::runnerScript(), $system->writes[$runner]);
        $this->assertSame('644', $system->modes[$runner]);
        $this->assertArrayHasKey('/engine/users/alice/entrypoint.d/' . AccountCron::INSTALL_FILE, $system->writes);
    }

    private function dind(bool $running, ?System $system = null): Dind
    {
        $model = new User();
        $model->username = 'alice';
        $model->details = ['UID' => 1500, 'GID' => 1501];

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system ?? $this->system());
        $dind->method('projectDirPath')->willReturn('/engine/users/alice');
        $dind->method('homeDirPath')->willReturn('/home/alice');
        $dind->method('composeFilePath')->willReturn('/engine/users/alice/docker-compose.yml');
        $dind->method('username')->willReturn('alice');
        $dind->method('userModel')->willReturn($model);
        $dind->method('isRunning')->willReturn($running);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }

    /** Records the commands it would run and the files it would write. */
    private function system(): System
    {
        return new class () extends System {
            /** @var list<array<int, string>> */
            public array $calls = [];

            /** @var array<string, string> */
            public array $writes = [];

            /** @var array<string, string|null> */
            public array $modes = [];

            public function __construct()
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = (array) $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->calls[] = (array) $cmd;

                return new Process(['true']);
            }

            public function filesystem(): Filesystem
            {
                $system = $this;

                return new class ($system) extends Filesystem {
                    public function __construct(private System $recorder)
                    {
                        parent::__construct($recorder);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->recorder->writes[$path] = $contents;
                        $this->recorder->modes[$path] = $chmod;
                    }
                };
            }
        };
    }

    private function cron(Dind $dind): AccountCron
    {
        return new AccountCron($dind);
    }

    /** A docker that lists $containers for `ps` and runs `exec`'s command here, with the container's id. */
    private function fakeDocker(string $containers): void
    {
        $listing = $this->root . '/containers';
        file_put_contents($listing, $containers);
        $docker = <<<SH
            #!/bin/sh
            if [ "\$1" = ps ]; then echo "\$*" > {$this->root}/ps-args; cat {$listing}; exit 0; fi
            if [ "\$1" = exec ]; then FAKE_CONTAINER=\$2; export FAKE_CONTAINER; shift 2; exec "\$@"; fi
            exit 1
            SH;
        file_put_contents($this->root . '/bin/docker', $docker);
        chmod($this->root . '/bin/docker', 0755);
    }

    private function runRunner(string $service, string $command): void
    {
        $runner = $this->root . '/runner';
        file_put_contents($runner, AccountCron::runnerScript());
        $process = proc_open(
            ['/bin/sh', $runner, $service, base64_encode($command)],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['HOME' => $this->root . '/home', 'PATH' => $this->root . '/bin:' . getenv('PATH')]
        );
        $this->assertSame(0, proc_close($process));
    }

    private function log(): string
    {
        return (string) file_get_contents($this->root . '/home/' . AccountCron::LOG);
    }

    private function syntaxCheck(string $script): int
    {
        $file = $this->root . '/check.sh';
        file_put_contents($file, $script);
        exec('/bin/sh -n ' . escapeshellarg($file), $output, $status);

        return $status;
    }
}
