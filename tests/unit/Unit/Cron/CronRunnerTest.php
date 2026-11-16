<?php

namespace Tests\Unit\Cron;

use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project;
use App\System\Project\CronRunner;
use App\System\Project\Dind\AccountCron;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A classic account's cron has no mail transport it can reach, so a job's line calls the runner, which runs the
 * command and appends what it printed, with the time and the exit status, to the account's cron log.
 */
class CronRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-cron-runner-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir() . '/entrypoint-init.d', 0777, true);
        mkdir($this->homeDir(), 0777, true);
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
    public function test_the_command_reads_back_as_written(string $command): void
    {
        $stored = CronRunner::command($command);

        $this->assertSame($command, CronRunner::parse($stored));
        $this->assertDoesNotMatchRegularExpression('/[%\x27"$#>]/', $stored);
    }

    /** A container project's cron writes to the same path, so a project switched from one kind to the other keeps one log. */
    public function test_the_log_is_the_one_a_container_project_writes(): void
    {
        $this->assertSame(AccountCron::LOG, CronRunner::LOG);
    }

    public function test_a_command_not_written_for_the_runner_is_left_alone(): void
    {
        $this->assertNull(CronRunner::parse('php artisan schedule:run'));
        $this->assertNull(CronRunner::parse('/bin/sh ' . CronRunner::RUNNER . ' not*base64'));
        $this->assertNull(CronRunner::parse('/bin/sh /entrypoint.d/cron-in-service app ' . base64_encode('echo a')));
    }

    public function test_the_longest_job_fits_crons_command_limit(): void
    {
        $stored = CronRunner::command(str_repeat('x', CronRunner::MAX_COMMAND_BYTES));

        $this->assertLessThan(1000, strlen($stored));
    }

    public function test_a_failing_job_is_written_to_the_log_with_its_start_and_exit(): void
    {
        $this->runRunner('ls /nonexistent-fe120-directory; exit 3');

        $log = $this->log();
        $this->assertStringContainsString('start: ls /nonexistent-fe120-directory; exit 3', $log);
        $this->assertStringContainsString('No such file or directory', $log);
        $this->assertStringContainsString('exit 3: ls /nonexistent-fe120-directory; exit 3', $log);
    }

    public function test_the_commands_own_redirection_is_kept(): void
    {
        $this->runRunner('echo kept > ' . $this->root . '/out');

        $this->assertSame("kept\n", file_get_contents($this->root . '/out'));
        $this->assertStringContainsString('exit 0: echo kept > ' . $this->root . '/out', $this->log());
    }

    public function test_a_run_still_going_keeps_the_next_one_out(): void
    {
        $command = 'touch ' . $this->root . '/out';
        $dir = dirname($this->logPath());
        mkdir($dir, 0777, true);
        $holder = proc_open(['flock', $dir . '/' . md5(base64_encode($command)) . '.lock', 'sleep', '5'], [], $pipes);
        usleep(300_000);

        try {
            $this->runRunner($command);
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }

        $this->assertFileDoesNotExist($this->root . '/out');
        $this->assertStringContainsString('skipped: the previous run has not finished: ' . $command, $this->log());
    }

    /** The lock is the command's, so only a run of the same command is kept out, whatever schedule it is on. */
    public function test_another_command_runs_while_one_is_still_going(): void
    {
        $dir = dirname($this->logPath());
        mkdir($dir, 0777, true);
        $holder = proc_open(['flock', $dir . '/' . md5(base64_encode('sleep 5')) . '.lock', 'sleep', '5'], [], $pipes);
        usleep(300_000);

        try {
            $this->runRunner('touch ' . $this->root . '/out');
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }

        $this->assertFileExists($this->root . '/out');
    }

    public function test_install_writes_the_runner_and_hands_the_log_directory_to_the_account(): void
    {
        $system = $this->system();
        $runner = $this->runner($system);

        $runner->install();

        $path = $this->projectDir() . '/entrypoint-init.d/' . CronRunner::RUNNER_FILE;
        $this->assertSame($runner->runnerScript(), $system->writes[$path]);
        $this->assertSame('644', $system->modes[$path]);
        $this->assertSame([
            Privileged::files()->makeDirectories(dirname($this->logPath())),
            Privileged::files()->changeOwner('1500:1501', dirname($this->logPath())),
            Privileged::files()->changeMode('755', dirname($this->logPath())),
        ], $system->calls);
    }

    public function test_the_runner_names_the_log_by_absolute_path_and_is_valid_shell(): void
    {
        $script = $this->runner($this->system())->runnerScript();

        $this->assertStringContainsString('log=' . $this->logPath(), $script);
        $this->assertSame(0, $this->syntaxCheck($script));
    }

    private function runner(System $system): CronRunner
    {
        $project = $this->createStub(Project::class);
        $project->method('system')->willReturn($system);
        $project->method('homeDirPath')->willReturn($this->homeDir());
        $project->method('projectDirPath')->willReturn($this->projectDir());
        $project->method('userModel')->willReturn($this->user());

        return new CronRunner($project);
    }

    private function user(): User
    {
        $model = new User();
        $model->username = 'alice';
        $model->details = ['UID' => 1500, 'GID' => 1501];

        return $model;
    }

    /** Runs the script as cron would, with the command in base64. */
    private function runRunner(string $command): void
    {
        $script = $this->root . '/runner';
        file_put_contents($script, $this->runner($this->system())->runnerScript());
        $process = proc_open(
            ['/bin/sh', $script, base64_encode($command)],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        proc_close($process);
    }

    private function homeDir(): string
    {
        return $this->root . '/home/alice';
    }

    private function projectDir(): string
    {
        return $this->root . '/engine/users/alice';
    }

    private function logPath(): string
    {
        return $this->homeDir() . '/' . CronRunner::LOG;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logPath());
    }

    private function syntaxCheck(string $script): int
    {
        $file = $this->root . '/check.sh';
        file_put_contents($file, $script);
        exec('/bin/sh -n ' . escapeshellarg($file), $output, $status);

        return $status;
    }

    /** Records what it is asked to run, and what it is asked to write. */
    private function system(): System
    {
        return new class () extends System {
            /** @var array<string, string> */
            public array $writes = [];

            /** @var array<string, string|null> */
            public array $modes = [];

            /** @var list<array<int, string>> */
            public array $calls = [];

            public function __construct() {}

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
}
