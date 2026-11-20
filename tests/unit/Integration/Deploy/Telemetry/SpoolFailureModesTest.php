<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Telemetry\Spool;
use App\System\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\RecordingRunner;

/**
 * The spool's answers when a write, a read or a chown does not go to plan.
 */
class SpoolFailureModesTest extends TestCase
{
    private string $dir;

    private RecordingRunner $processes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-spool-fail-'.bin2hex(random_bytes(6));
        $this->processes = new RecordingRunner;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function spool(?ProcessRunner $processes = null): Spool
    {
        return new Spool($this->dir, $processes ?? $this->processes);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $id): array
    {
        return ['id' => $id, 'outcome' => 'failed'];
    }

    public function test_a_report_json_cannot_encode_is_not_queued(): void
    {
        $this->assertNull($this->spool()->put(['id' => 'r1', 'outcome' => 'failed', 'ratio' => INF]));

        $this->assertSame([], glob($this->dir.'/*.json'));
    }

    public function test_a_report_whose_file_cannot_be_renamed_into_place_is_not_queued(): void
    {
        mkdir($this->dir.'/r1.json', 0777, true);

        $this->assertNull($this->spool()->put($this->report('r1')));

        $this->assertSame([], glob($this->dir.'/r1.json.tmp.*'));
    }

    public function test_a_report_whose_temporary_file_cannot_be_written_is_not_queued(): void
    {
        mkdir($this->dir.'/r1.json.tmp.'.getmypid(), 0777, true);

        $this->assertNull($this->spool()->put($this->report('r1')));

        $this->assertFileDoesNotExist($this->dir.'/r1.json');
    }

    public function test_pending_stops_at_its_limit(): void
    {
        $spool = $this->spool();
        foreach (['a1', 'a2', 'a3'] as $id) {
            $spool->put($this->report($id));
        }

        $this->assertSame(['a1', 'a2'], array_column($spool->pending(2), 'id'));
    }

    public function test_a_failure_recorded_for_a_vanished_report_gives_it_up_with_its_bundle(): void
    {
        $spool = $this->spool();
        $spool->ensureDirectory();
        file_put_contents($this->dir.'/gone.zip', 'PK');

        $this->assertFalse($spool->recordFailure($this->dir.'/gone.json'));

        $this->assertFileDoesNotExist($this->dir.'/gone.zip');
    }

    public function test_the_last_allowed_failure_drops_the_report_and_its_bundle(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('r1'));
        file_put_contents($this->dir.'/r1.zip', 'PK');

        for ($i = 1; $i < Spool::MAX_ATTEMPTS; $i++) {
            $this->assertTrue($spool->recordFailure($path));
        }
        $this->assertFalse($spool->recordFailure($path));

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($this->dir.'/r1.zip');
    }

    public function test_a_deferral_backs_off_without_spending_an_attempt(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('r1'));
        $before = time();

        $spool->recordDeferral($path);

        $envelope = (array) $spool->read($path);
        $this->assertSame(1, $envelope['deferrals']);
        $this->assertSame(0, $envelope['attempts']);
        $this->assertGreaterThanOrEqual($before, $envelope['last_attempt_at']);
        $this->assertSame([], $spool->pending(10, $before));
        $this->assertCount(1, $spool->pending(10, $before + Spool::backoffSeconds(1) + 5));
    }

    public function test_a_second_deferral_counts_on(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('r1'));

        $spool->recordDeferral($path);
        $spool->recordDeferral($path);

        $this->assertSame(2, ((array) $spool->read($path))['deferrals']);
    }

    public function test_a_deferral_for_an_unreadable_report_forgets_it(): void
    {
        $spool = $this->spool();
        $spool->ensureDirectory();
        file_put_contents($this->dir.'/r1.json', 'not json');
        file_put_contents($this->dir.'/r1.zip', 'PK');

        $spool->recordDeferral($this->dir.'/r1.json');

        $this->assertFileDoesNotExist($this->dir.'/r1.json');
        $this->assertFileDoesNotExist($this->dir.'/r1.zip');
    }

    public function test_read_answers_null_for_a_missing_an_empty_and_a_scalar_file(): void
    {
        $spool = $this->spool();
        $spool->ensureDirectory();
        file_put_contents($this->dir.'/empty.json', '');
        file_put_contents($this->dir.'/scalar.json', '42');

        $this->assertNull($spool->read($this->dir.'/missing.json'));
        $this->assertNull($spool->read($this->dir.'/empty.json'));
        $this->assertNull($spool->read($this->dir.'/scalar.json'));
    }

    public function test_claiming_the_root_or_nothing_runs_no_chown(): void
    {
        $spool = $this->spool();

        $spool->claimOwnership('');
        $spool->claimOwnership('/');

        $this->assertSame([], $this->processes->commands);
    }

    public function test_a_chown_that_throws_does_not_lose_the_report(): void
    {
        $spool = $this->spool($this->throwingRunner());

        $path = $spool->put($this->report('r1'));

        $this->assertSame($this->dir.'/r1.json', $path);
        $this->assertSame('r1', ((array) $spool->read((string) $path))['report']['id']);
    }

    private function throwingRunner(): ProcessRunner
    {
        return new class implements ProcessRunner
        {
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('no broker');
            }

            public function execOnHost(array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('no broker');
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('no broker');
            }

            public function runProcessOnHost(array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('no broker');
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                throw new \RuntimeException('no broker');
            }
        };
    }
}
