<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\Spool;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingRunner;

class SpoolTest extends TestCase
{
    private string $dir;

    /** The chown the spool does is faked: a test must not run sudo. */
    private RecordingRunner $processes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-spool-' . bin2hex(random_bytes(6));
        $this->processes = new RecordingRunner();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function spool(): Spool
    {
        return new Spool($this->dir, $this->processes);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $id): array
    {
        return ['id' => $id, 'outcome' => 'failed', 'deploy' => ['strategy' => 'nextjs']];
    }

    public function test_put_then_pending_round_trips_the_report(): void
    {
        $spool = $this->spool();
        $this->assertNotNull($spool->put($this->report('01JGXR0000000000000000000A')));

        $pending = $spool->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('01JGXR0000000000000000000A', $pending[0]['id']);
        $this->assertSame('nextjs', $pending[0]['report']['deploy']['strategy']);
        $this->assertSame(0, $pending[0]['attempts']);
    }

    public function test_creates_its_directory_on_first_write(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->spool()->put($this->report('01JGXR0000000000000000000A'));
        $this->assertDirectoryExists($this->dir);
    }

    /**
     * Cron ships as www-data while `pae` often writes as root. Ownership is
     * claimed via `sudo chown` (best-effort, same as LogStorage) — not asserted
     * here because unit tests have no sudo rule. What is covered is that put()
     * still leaves a directory and report file the process can read.
     */
    public function test_put_leaves_a_readable_directory_and_report_file(): void
    {
        $spool = $this->spool();
        $path = $spool->put($this->report('01JGXR0000000000000000000A'));

        $this->assertNotNull($path);
        $this->assertDirectoryExists($this->dir);
        $this->assertDirectoryIsWritable($this->dir);
        $this->assertFileExists((string) $path);
        $this->assertIsReadable((string) $path);
        $this->assertSame(1, $spool->stats()['count']);
    }

    public function test_claim_ownership_is_safe_to_call_on_a_bundle_path(): void
    {
        $spool = $this->spool();
        $spool->ensureDirectory();
        $bundle = (string) $spool->bundlePathFor('01JGXR0000000000000000000A');
        file_put_contents($bundle, 'PK zip bytes');

        $spool->claimOwnership($bundle);

        $this->assertFileExists($bundle);
        $this->assertIsReadable($bundle);
    }

    public function test_refuses_a_report_with_an_unusable_id(): void
    {
        $spool = $this->spool();

        $this->assertNull($spool->put(['id' => '../../etc/passwd']));
        $this->assertNull($spool->put(['id' => '']));
        $this->assertNull($spool->put(['outcome' => 'failed']));
    }

    public function test_pending_returns_oldest_first(): void
    {
        $spool = $this->spool();
        foreach (['01JGXR0000000000000000000C', '01JGXR0000000000000000000A', '01JGXR0000000000000000000B'] as $id) {
            $spool->put($this->report($id));
        }

        $this->assertSame(
            ['01JGXR0000000000000000000A', '01JGXR0000000000000000000B', '01JGXR0000000000000000000C'],
            array_column($spool->pending(), 'id')
        );
    }

    public function test_forget_removes_a_sent_report(): void
    {
        $spool = $this->spool();
        $path = $spool->put($this->report('01JGXR0000000000000000000A'));

        $spool->forget((string) $path);

        $this->assertSame([], $spool->pending());
    }

    public function test_a_just_failed_report_is_held_back_until_its_backoff_elapses(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('01JGXR0000000000000000000A'));

        $this->assertTrue($spool->recordFailure($path));

        $now = time();
        $this->assertSame([], $spool->pending(100, $now), 'retried immediately');
        $this->assertCount(1, $spool->pending(100, $now + Spool::backoffSeconds(1) + 1));
    }

    public function test_backoff_doubles_and_is_capped(): void
    {
        $this->assertSame(0, Spool::backoffSeconds(0));
        $this->assertSame(120, Spool::backoffSeconds(1));
        $this->assertSame(240, Spool::backoffSeconds(2));
        $this->assertSame(6 * 3600, Spool::backoffSeconds(99));
    }

    public function test_a_report_is_given_up_on_after_the_attempt_limit(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('01JGXR0000000000000000000A'));

        for ($i = 1; $i < Spool::MAX_ATTEMPTS; $i++) {
            $this->assertTrue($spool->recordFailure($path), "gave up early at attempt {$i}");
        }

        $this->assertFalse($spool->recordFailure($path));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_corrupt_file_is_dropped_rather_than_retried_forever(): void
    {
        $spool = $this->spool();
        $spool->put($this->report('01JGXR0000000000000000000A'));
        file_put_contents($this->dir . '/01JGXR0000000000000000000B.json', 'not json');

        $pending = $spool->pending();

        $this->assertCount(1, $pending);
        $this->assertFileDoesNotExist($this->dir . '/01JGXR0000000000000000000B.json');
    }

    /**
     * An install that has been offline for a month must not fill its own disk
     * with reports about deploys nobody will ever read.
     */
    public function test_the_outbox_is_capped_and_drops_the_oldest(): void
    {
        $spool = $this->spool();
        for ($i = 0; $i < Spool::MAX_FILES + 10; $i++) {
            $spool->put($this->report(sprintf('01JGXR%020d', $i)));
        }

        $stats = $spool->stats();
        $this->assertSame(Spool::MAX_FILES, $stats['count']);

        // The newest survived, the oldest did not.
        $ids = array_column($spool->pending(PHP_INT_MAX), 'id');
        $this->assertContains(sprintf('01JGXR%020d', Spool::MAX_FILES + 9), $ids);
        $this->assertNotContains(sprintf('01JGXR%020d', 0), $ids);
    }

    public function test_stats_report_an_empty_spool_without_creating_it(): void
    {
        $stats = $this->spool()->stats();

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0, $stats['bytes']);
        $this->assertNull($stats['oldest']);
    }

    // ── source bundles ────────────────────────────────────────────────────

    public function test_pending_reports_an_attached_bundle(): void
    {
        $spool = $this->spool();
        $spool->put($this->report('01JGXR0000000000000000000A'));
        file_put_contents($spool->bundlePathFor('01JGXR0000000000000000000A'), 'PK zip bytes');

        $pending = $spool->pending();

        $this->assertNotNull($pending[0]['bundle']);
        $this->assertFileExists($pending[0]['bundle']);
    }

    public function test_pending_reports_no_bundle_when_there_is_none(): void
    {
        $spool = $this->spool();
        $spool->put($this->report('01JGXR0000000000000000000A'));

        $this->assertNull($spool->pending()[0]['bundle']);
    }

    /**
     * The bundle is the customer's source code sitting on their own disk. Once
     * the report it belongs to is settled, nothing will ever send it, and
     * leaving it behind is an unattended copy of their repository.
     */
    public function test_forgetting_a_report_deletes_its_bundle(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('01JGXR0000000000000000000A'));
        $bundle = (string) $spool->bundlePathFor('01JGXR0000000000000000000A');
        file_put_contents($bundle, 'PK zip bytes');

        $spool->forget($path);

        $this->assertFileDoesNotExist($bundle);
    }

    public function test_giving_up_on_a_report_after_the_attempt_limit_deletes_its_bundle(): void
    {
        $spool = $this->spool();
        $path = (string) $spool->put($this->report('01JGXR0000000000000000000A'));
        $bundle = (string) $spool->bundlePathFor('01JGXR0000000000000000000A');
        file_put_contents($bundle, 'PK zip bytes');

        for ($i = 1; $i < Spool::MAX_ATTEMPTS; $i++) {
            $spool->recordFailure($path);
        }
        $this->assertFalse($spool->recordFailure($path));

        $this->assertFileDoesNotExist($bundle);
    }

    /**
     * A zip whose report never got written — the disk filled between the two
     * writes, say. Nothing will ever send it.
     */
    public function test_an_orphaned_bundle_is_swept_up(): void
    {
        $spool = $this->spool();
        $spool->put($this->report('01JGXR0000000000000000000A'));
        $orphan = (string) $spool->bundlePathFor('01JGXR000000000000000000ZZ');
        file_put_contents($orphan, 'PK zip bytes');

        // prune() runs on every put()
        $spool->put($this->report('01JGXR0000000000000000000B'));

        $this->assertFileDoesNotExist($orphan);
    }

    public function test_stats_count_bundles_separately_from_reports(): void
    {
        $spool = $this->spool();
        $spool->put($this->report('01JGXR0000000000000000000A'));
        file_put_contents($spool->bundlePathFor('01JGXR0000000000000000000A'), str_repeat('z', 4096));

        $stats = $spool->stats();

        $this->assertSame(1, $stats['count']);
        $this->assertSame(1, $stats['bundles']);
        $this->assertSame(4096, $stats['bundle_bytes']);
    }

    public function test_a_bundle_path_is_refused_for_an_unusable_id(): void
    {
        $this->assertNull($this->spool()->bundlePathFor('../../etc/passwd'));
    }

    public function test_a_bundle_is_never_mistaken_for_a_report(): void
    {
        $spool = $this->spool();
        $spool->ensureDirectory();
        file_put_contents($spool->bundlePathFor('01JGXR0000000000000000000A'), 'PK zip bytes');

        $this->assertSame([], $spool->pending());
    }
    public function test_a_file_with_no_report_inside_it_is_dropped_rather_than_sent(): void
    {
        $spool = new Spool($this->dir, $this->processes);
        mkdir($this->dir, 0700, true);

        // Valid JSON, no report: shipping this would produce a payload with no
        // id and no outcome, the ingest would refuse the whole batch as
        // malformed, and a 4xx is permanent -- taking every good report with
        // it.
        file_put_contents($this->dir . '/hollow.json', json_encode(['attempts' => 0]));
        file_put_contents($this->dir . '/half.json', json_encode(['report' => ['id' => 'x']]));
        $spool->put(['id' => '01JGXR0000000000000000000A', 'outcome' => 'failed']);

        $pending = $spool->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('01JGXR0000000000000000000A', $pending[0]['id']);
        $this->assertFileDoesNotExist($this->dir . '/hollow.json');
        $this->assertFileDoesNotExist($this->dir . '/half.json');
    }

    /** @return list<string> the paths the spool asked to chown */
    private function chowned(): array
    {
        $paths = [];
        foreach ($this->processes->commands as $cmd) {
            if (is_array($cmd) && ($cmd[1] ?? '') === 'chown') {
                $paths[] = (string) end($cmd);
            }
        }

        return $paths;
    }

    public function test_the_outbox_itself_is_claimed_for_www_data(): void
    {
        $this->spool()->ensureDirectory();

        $this->assertContains($this->dir, $this->chowned());
    }

    /**
     * A custom TELEMETRY_SPOOL_DIR such as /srv/outbox must not hand /srv to
     * www-data: outside the storage layout only the outbox itself is claimed.
     */
    public function test_a_parent_outside_the_storage_layout_is_left_alone(): void
    {
        $this->spool()->ensureDirectory();

        $this->assertSame([$this->dir], $this->chowned());
    }

    /** In the storage layout the walk claims outbox, telemetry and app, and stops there. */
    public function test_only_the_telemetry_and_app_parents_are_claimed(): void
    {
        $root = sys_get_temp_dir() . '/pa-spool-layout-' . bin2hex(random_bytes(4));
        $outbox = $root . '/storage/app/telemetry/outbox';
        mkdir($outbox, 0777, true);

        $runner = new RecordingRunner();
        (new Spool($outbox, $runner))->ensureDirectory();

        $chowned = [];
        foreach ($runner->commands as $cmd) {
            if (is_array($cmd) && ($cmd[1] ?? '') === 'chown') {
                $chowned[] = (string) end($cmd);
            }
        }

        $this->assertContains($outbox, $chowned);
        $this->assertContains($root . '/storage/app/telemetry', $chowned);
        $this->assertContains($root . '/storage/app', $chowned);
        $this->assertNotContains($root . '/storage', $chowned, 'the walk stops at app');

        foreach (array_reverse(glob($root . '/storage/app/telemetry/outbox') ?: []) as $d) {
            @rmdir($d);
        }
        foreach (['/storage/app/telemetry', '/storage/app', '/storage', ''] as $suffix) {
            @rmdir($root . $suffix);
        }
    }

    public function test_a_spool_test_never_runs_a_real_command(): void
    {
        $this->spool()->put(['id' => '01JGXR0000000000000000000A', 'outcome' => 'success']);

        $this->assertNotSame([], $this->processes->commands, 'the fake saw the chown');
        foreach ($this->processes->commands as $cmd) {
            $this->assertSame('sudo', $cmd[0] ?? null, 'only the ownership fix shells out');
            $this->assertSame('chown', $cmd[1] ?? null);
        }
    }
}
