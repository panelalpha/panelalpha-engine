<?php

namespace Tests\Integration\System;

use App\System;
use App\System\EngineUpdate;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

/**
 * Starting the engine updater and reading back what the last run did. It was
 * 110 lines inside App\System with no coverage at all.
 */
class EngineUpdateTest extends TestCase
{
    /** How the job keeps the updater's output, and its exit code when it fails, in the placeholder. */
    private const KEPT_IN_PLACEHOLDER = ' > ' . EngineUpdate::LOGS_DIR . '/tmp/stdout 2> ' . EngineUpdate::LOGS_DIR . '/tmp/stderr'
        . ' || echo $? > ' . EngineUpdate::LOGS_DIR . '/tmp/exit_code';

    private string $engineDir = '';

    /** The broker checks that the engine directory exists. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->engineDir = sys_get_temp_dir() . '/pa-engine-update-' . bin2hex(random_bytes(6));
        mkdir($this->engineDir, 0755);
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->engineDir]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        rmdir($this->engineDir);
        parent::tearDown();
    }

    private function system(FakeUpdateFilesystem $fs, int $exitCode = 0): FakeUpdateSystem
    {
        return new FakeUpdateSystem($fs, $this->engineDir, $exitCode);
    }

    /**
     * The single argument `bash -c` receives. Joining the argv with spaces would
     * read as a shell line it never is.
     *
     * @param list<string> $argv
     */
    private function payload(array $argv): string
    {
        $this->assertSame([LocalRoot::CLIENT, '--host'], array_slice($argv, 0, 2));
        $plain = LocalRoot::plain($argv);
        $this->assertSame(['bash', '-c'], array_slice($plain, 0, 2));

        return $plain[2];
    }

    public function test_no_latest_directory_means_there_has_never_been_a_run(): void
    {
        $fs = new FakeUpdateFilesystem();
        $fs->directories = [];

        $this->assertNull((new EngineUpdate($this->system($fs)))->latest());
    }

    public function test_a_finished_run_is_reported_in_full(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = [
            "{$dir}/pid" => '4321',
            "{$dir}/exit_code" => '0',
            "{$dir}/from_version" => '1.2.3',
            "{$dir}/to_version" => '1.3.0',
        ];
        $fs->tails = [
            "{$dir}/stdout" => "done\n",
            "{$dir}/stderr" => '',
        ];
        $fs->mtimes = ["{$dir}/pid" => 1000, "{$dir}/exit_code" => 2000];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertSame(1000, $latest['started_at']);
        $this->assertSame(2000, $latest['finished_at']);
        $this->assertSame(4321, $latest['pid']);
        $this->assertSame(0, $latest['exit_code']);
        $this->assertNull($latest['reason']);
        $this->assertSame('1.2.3', $latest['from_version']);
        $this->assertSame('1.3.0', $latest['to_version']);
        $this->assertSame($dir, $latest['logs_path']);
    }

    /** `latest` is a symlink re-pointed after the run ended, so its own time is not the run's start. */
    public function test_the_started_at_is_the_run_start_not_the_latest_symlink_time(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321', "{$dir}/exit_code" => '0'];
        $fs->mtimes = [$dir => 3000, "{$dir}/pid" => 1000, "{$dir}/exit_code" => 2000];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertSame(1000, $latest['started_at']);
        $this->assertSame(2000, $latest['finished_at']);
    }

    /** A pid or exit code that is not a number reads as "not known yet". */
    public function test_a_non_numeric_pid_or_exit_code_is_null(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '', "{$dir}/exit_code" => null];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertNull($latest['pid']);
        $this->assertNull($latest['exit_code']);
    }

    /** Colour codes are noise in a JSON field. */
    public function test_terminal_colour_codes_are_stripped_from_the_tails(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->tails = [
            "{$dir}/stdout" => "\x1B[32mgreen\x1B[0m text",
            "{$dir}/stderr" => "\x1B[1;31mred\x1B[Kline",
        ];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertSame('green text', $latest['tail_stdout']);
        $this->assertSame('redline', $latest['tail_stderr']);
    }

    public function test_an_absent_stream_stays_null(): void
    {
        $fs = new FakeUpdateFilesystem();
        $fs->directories = [EngineUpdate::LOGS_DIR . '/latest'];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertNull($latest['tail_stdout']);
        $this->assertNull($latest['tail_stderr']);
    }

    public function test_running_the_updater_enters_the_host_namespaces(): void
    {
        $fs = new FakeUpdateFilesystem();
        $system = $this->system($fs);

        (new EngineUpdate($system))->run();

        $payload = $this->payload($system->commands[1]);
        $this->assertStringContainsString('updater.sh', $payload);
        $this->assertStringContainsString('| at now', $payload, 'the updater is detached');
    }

    /**
     * The key reaches a shell twice -- once building the `at` job, once running
     * it -- so it is escaped for both. Asserting on the exact payload rather
     * than on the absence of the characters: they do still appear, quoted and
     * inert, which is what escaping looks like.
     */
    public function test_a_license_key_is_passed_through_escaped(): void
    {
        $fs = new FakeUpdateFilesystem();
        $system = $this->system($fs);
        $key = "key' ; rm -rf /";

        (new EngineUpdate($system))->run($key);

        $updater = 'bash ' . escapeshellarg($system->engineDirPath() . '/updater.sh')
            . ' -f --background ' . escapeshellarg($key) . self::KEPT_IN_PLACEHOLDER;

        $this->assertSame(
            'echo ' . escapeshellarg($updater) . ' | at now',
            $this->payload($system->commands[1])
        );
    }

    public function test_no_license_key_adds_no_argument(): void
    {
        $fs = new FakeUpdateFilesystem();
        $system = $this->system($fs);

        (new EngineUpdate($system))->run();

        $updater = 'bash ' . escapeshellarg($system->engineDirPath() . '/updater.sh')
            . ' -f --background' . self::KEPT_IN_PLACEHOLDER;

        $this->assertSame(
            'echo ' . escapeshellarg($updater) . ' | at now',
            $this->payload($system->commands[1])
        );
    }

    /** The script writes its own `latest` only later, so one is seeded first, before the job can write into it. */
    public function test_a_latest_directory_is_seeded_so_progress_can_be_read_immediately(): void
    {
        $fs = new FakeUpdateFilesystem();
        $system = $this->system($fs);

        (new EngineUpdate($system))->run();

        $this->assertCount(2, $system->commands);
        $seed = $this->payload($system->commands[0]);
        $this->assertStringContainsString('mkdir -p ' . EngineUpdate::LOGS_DIR . '/tmp', $seed);
        $this->assertStringContainsString('ln -sfn', $seed);
        // A timestamp, not pid 2 (kthreadd, always alive): the placeholder must
        // not read as a live process, or one failed download blocks every later
        // update for good.
        $this->assertStringContainsString('date +%s > ' . EngineUpdate::LOGS_DIR . '/tmp/' . EngineUpdate::PENDING_FILE, $seed);
        $this->assertStringNotContainsString("echo '2' >", $seed);
        $tmp = EngineUpdate::LOGS_DIR . '/tmp';
        $this->assertStringContainsString("rm -f {$tmp}/pid {$tmp}/exit_code {$tmp}/stdout {$tmp}/stderr", $seed, 'an earlier run leaves nothing behind');
    }

    /** `at` refusing the job is a run that failed before it started, and it must not block the next one. */
    public function test_a_refused_job_leaves_a_failed_placeholder(): void
    {
        $system = $this->system(new FakeUpdateFilesystem(), 3);

        try {
            (new EngineUpdate($system))->run();
        } catch (\Exception) {
        }

        $this->assertCount(3, $system->commands);
        $tmp = EngineUpdate::LOGS_DIR . '/tmp';
        $this->assertSame("printf %s \"\$1\" > {$tmp}/stderr && echo \"\$2\" > {$tmp}/exit_code", $this->payload($system->commands[2]));
        $this->assertSame(['bash', 'boom', '3'], array_slice(LocalRoot::plain($system->commands[2]), 3));
    }

    public function test_a_placeholder_that_never_became_a_run_is_failed(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) (time() - EngineUpdate::PENDING_GRACE_SECONDS - 60)];
        $fs->tails = ["{$dir}/stderr" => "curl: (6) Could not resolve host: get.panelalpha.com\n"];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertNull($latest['exit_code']);
        $this->assertSame("curl: (6) Could not resolve host: get.panelalpha.com\n", $latest['tail_stderr']);
        $this->assertSame('the update job never started', $latest['reason'], 'a tail is two lines of output, not a reason');
    }

    /** It has no output at all, so the answer has to say the job never started. */
    public function test_a_run_that_never_started_reports_a_reason(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) (time() - EngineUpdate::PENDING_GRACE_SECONDS - 60)];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertSame('the update job never started', $latest['reason']);
    }

    /** It did start -- the pid is there -- but it died before writing a result. */
    public function test_a_process_that_died_without_an_exit_code_reports_a_reason(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321'];

        $latest = (new EngineUpdate($this->system($fs, 1)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertSame('the update process ended without writing an exit code', $latest['reason']);
    }

    /** A process killed mid-run leaves output but no exit code; it failed, and the answer says so and when it stopped. */
    public function test_a_run_killed_after_writing_output_is_failed_with_a_reason_and_a_finish_time(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = [
            "{$dir}/pid" => "1626378\n",
            "{$dir}/from_version" => "2.3.0\n",
            "{$dir}/to_version" => '2.3.1',
        ];
        $fs->tails = ["{$dir}/stdout" => ">>> Installing composer dependencies\n"];
        $fs->mtimes = ["{$dir}/pid" => 1000, "{$dir}/stdout" => 1300];

        $latest = (new EngineUpdate($this->system($fs, 1)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertNull($latest['exit_code']);
        $this->assertSame('the update process ended without writing an exit code', $latest['reason']);
        $this->assertSame(1300, $latest['finished_at'], 'when it last wrote output');
        $this->assertSame('2.3.0', $latest['from_version']);
        $this->assertSame('2.3.1', $latest['to_version']);
        $this->assertNull($latest['result']);
    }

    /** One killed before its first line of output finished when it started, as far as anything shows. */
    public function test_a_run_killed_before_any_output_finished_when_it_started(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321'];
        $fs->mtimes = ["{$dir}/pid" => 1000];

        $this->assertSame(1000, (new EngineUpdate($this->system($fs, 1)))->latest()['finished_at']);
    }

    /** A live run has not finished, whatever it has written so far. */
    public function test_a_running_update_has_no_finish_time(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321'];
        $fs->mtimes = ["{$dir}/pid" => 1000, "{$dir}/stdout" => 1300];

        $latest = (new EngineUpdate($this->system($fs, 0)))->latest();

        $this->assertFalse($latest['failed']);
        $this->assertNull($latest['finished_at']);
        $this->assertNull($latest['reason']);
    }

    /** A target that is the running release changes nothing; the caller is told so, not handed a bare success. */
    public function test_an_update_with_nothing_newer_says_it_is_up_to_date(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = [
            "{$dir}/pid" => '4321',
            "{$dir}/exit_code" => "0\n",
            "{$dir}/result" => 'up_to_date',
            "{$dir}/reason" => 'Already up to date: 2.3.0 (b9f8c105) is running and main is the same commit. Nothing was changed.',
            "{$dir}/from_version" => '2.3.0',
            "{$dir}/to_version" => '2.3.0',
        ];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertFalse($latest['failed']);
        $this->assertSame('up_to_date', $latest['result']);
        $this->assertSame('Already up to date: 2.3.0 (b9f8c105) is running and main is the same commit. Nothing was changed.', $latest['reason']);
    }

    /** An older target is refused before anything changed, and the record says why rather than "exit code 1". */
    public function test_a_refused_downgrade_reports_its_own_reason(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $reason = 'Refusing to install 2.2.0 (main) over the running 2.3.0: it is an older release. Nothing was changed.';
        $fs->files = [
            "{$dir}/pid" => '4321',
            "{$dir}/exit_code" => "1\n",
            "{$dir}/result" => "refused\n",
            "{$dir}/reason" => $reason,
            "{$dir}/from_version" => '2.3.0',
            "{$dir}/to_version" => '2.2.0',
        ];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertSame('refused', $latest['result']);
        $this->assertSame($reason, $latest['reason']);
        $this->assertSame('2.2.0', $latest['to_version']);
    }

    public function test_a_rolled_back_update_reports_it(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = [
            "{$dir}/pid" => '4321',
            "{$dir}/exit_code" => '101',
            "{$dir}/result" => 'rolled_back',
            "{$dir}/reason" => 'These engine services are not running: core(exited); the previous release was restored',
        ];
        $fs->mtimes = ["{$dir}/pid" => 1000, "{$dir}/exit_code" => 1500];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertTrue($latest['failed']);
        $this->assertSame(101, $latest['exit_code']);
        $this->assertSame(1500, $latest['finished_at']);
        $this->assertSame('rolled_back', $latest['result']);
        $this->assertStringEndsWith('the previous release was restored', $latest['reason']);
    }

    /** An installer that records no reason still fails with one. */
    public function test_a_failed_exit_without_a_recorded_reason_names_its_exit_code(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321', "{$dir}/exit_code" => "101\n"];
        $fs->tails = ["{$dir}/stdout" => ">>> Not enough disk space. Required: 15G, available: 7G\n"];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertSame('the update exited with code 101', $latest['reason']);
        $this->assertNull($latest['result']);
    }

    /** A version file left empty is not known, not the empty string. */
    public function test_empty_record_files_are_null(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = [
            "{$dir}/exit_code" => '0',
            "{$dir}/from_version" => "\n",
            "{$dir}/to_version" => '',
            "{$dir}/result" => ' ',
            "{$dir}/reason" => '',
        ];

        $latest = (new EngineUpdate($this->system($fs)))->latest();

        $this->assertNull($latest['from_version']);
        $this->assertNull($latest['to_version']);
        $this->assertNull($latest['result']);
        $this->assertNull($latest['reason']);
    }

    public function test_a_placeholder_within_the_grace_has_not_failed(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) time()];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->latest()['failed']);
    }

    /** The updater died without writing an exit code: its pid no longer answers kill -0. */
    public function test_a_run_whose_process_is_gone_without_an_exit_code_is_failed(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321'];

        $this->assertTrue((new EngineUpdate($this->system($fs, 1)))->latest()['failed']);
        $this->assertFalse((new EngineUpdate($this->system($fs, 0)))->latest()['failed'], 'a live one is still running');
    }

    public function test_the_exit_code_decides_once_there_is_one(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321', "{$dir}/exit_code" => "1\n"];
        $system = $this->system($fs);

        $this->assertTrue((new EngineUpdate($system))->latest()['failed']);
        $fs->files["{$dir}/exit_code"] = "0\n";
        $this->assertFalse((new EngineUpdate($system))->latest()['failed']);
        $this->assertSame([], $system->commands, 'a finished run is not probed with kill -0');
    }

    /** The job writes the updater's own exit code into the placeholder when it fails, before the grace is over. */
    public function test_a_placeholder_with_an_exit_code_is_not_running(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) time(), "{$dir}/exit_code" => "1\n"];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->isRunning());
    }

    /** A placeholder that has just been seeded is a live attempt. */
    public function test_a_fresh_placeholder_counts_as_running(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) time()];

        $this->assertTrue((new EngineUpdate($this->system($fs)))->isRunning());
    }

    /** One that never replaced its placeholder is a run that never started. */
    public function test_a_stale_placeholder_is_not_running(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => (string) (time() - EngineUpdate::PENDING_GRACE_SECONDS - 60)];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->isRunning());
    }

    /** An unreadable timestamp must not block every later update. */
    public function test_a_placeholder_without_a_timestamp_is_not_running(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/" . EngineUpdate::PENDING_FILE => ''];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->isRunning());
    }

    /** Past the placeholder, a real pid is still checked with kill -0. */
    public function test_a_real_pid_is_still_checked(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => '4321'];
        $system = $this->system($fs);

        $this->assertTrue((new EngineUpdate($system))->isRunning());
        $this->assertSame([LocalRoot::CLIENT, '--host', 'host.processExists', '--', '4321'], $system->commands[0]);
        $this->assertSame(['kill', '-0', '--', '4321'], LocalRoot::plain($system->commands[0]));
    }

    /** An older engine seeded its placeholder with pid 2, which is the host's kthreadd and always answers kill -0. */
    public function test_a_placeholder_seeded_by_an_older_engine_is_not_running(): void
    {
        $fs = new FakeUpdateFilesystem();
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $fs->directories = [$dir];
        $fs->files = ["{$dir}/pid" => "2\n"];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->isRunning());
    }

    public function test_a_failed_start_is_reported_with_its_exit_code(): void
    {
        $fs = new FakeUpdateFilesystem();
        $system = $this->system($fs, 3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to run update script');

        (new EngineUpdate($system))->run();
    }

    public function test_nothing_is_running_without_a_latest_directory(): void
    {
        $fs = new FakeUpdateFilesystem();
        $fs->directories = [];

        $this->assertFalse((new EngineUpdate($this->system($fs)))->isRunning());
    }
}

/** @internal */
final class FakeUpdateFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $directories = [];

    /** @var array<string, ?string> */
    public array $files = [];

    /** @var array<string, ?string> */
    public array $tails = [];

    /** @var array<string, ?int> */
    public array $mtimes = [];

    public function __construct()
    {
    }

    public function directoryExists(string $path): bool
    {
        return in_array($path, $this->directories, true);
    }

    public function cat(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function tail(string $path, int $lines): ?string
    {
        return $this->tails[$path] ?? null;
    }

    public function mtime(string $path): ?int
    {
        return $this->mtimes[$path] ?? null;
    }
}

/** @internal */
final class FakeUpdateSystem extends System
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(
        private FakeUpdateFilesystem $fs,
        private string $engineDir,
        private int $exitCode = 0,
    ) {
    }

    public function engineDirPath(): string
    {
        return $this->engineDir;
    }

    public function filesystem(): Filesystem
    {
        return $this->fs;
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = is_array($cmd) ? array_values($cmd) : [$cmd];

        return $this->exitCode === 0 ? FakeProcess::ok() : FakeProcess::failed('boom', $this->exitCode);
    }
}
