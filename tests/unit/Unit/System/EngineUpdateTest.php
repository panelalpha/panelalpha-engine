<?php

namespace Tests\Unit\System;

use App\System;
use App\System\EngineUpdate;
use App\System\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

/**
 * Starting the engine updater and reading back what the last run did. It was
 * 110 lines inside App\System with no coverage at all.
 */
class EngineUpdateTest extends TestCase
{
    /** How the job keeps the updater's output, and its exit code when it fails, in the placeholder. */
    private const KEPT_IN_PLACEHOLDER = ' > ' . EngineUpdate::LOGS_DIR . '/tmp/stdout 2> ' . EngineUpdate::LOGS_DIR . '/tmp/stderr'
        . ' || echo $? > ' . EngineUpdate::LOGS_DIR . '/tmp/exit_code';

    private function system(FakeUpdateFilesystem $fs, int $exitCode = 0): FakeUpdateSystem
    {
        return new FakeUpdateSystem($fs, $exitCode);
    }

    /**
     * The single argument `bash -c` receives. Joining the argv with spaces would
     * read as a shell line it never is.
     *
     * @param list<string> $argv
     */
    private function payload(array $argv): string
    {
        $this->assertSame(['sudo', 'nsenter', '--target', '1', '--all', 'bash', '-c'], array_slice($argv, 0, 7));

        return $argv[7];
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
        $this->assertSame("printf %s 'boom' > {$tmp}/stderr && echo 3 > {$tmp}/exit_code", $this->payload($system->commands[2]));
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
        $this->assertNull($latest['reason'], 'the tail already says why');
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
        $this->assertSame(['sudo', 'nsenter', '--target', '1', '--all', 'kill', '-0', '4321'], $system->commands[0]);
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
        private int $exitCode = 0,
    ) {
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
