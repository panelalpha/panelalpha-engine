<?php

namespace Tests\Unit\Support;

use App\Support\QueueWorkers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueWorkersTest extends TestCase
{
    private string $scanDir;

    private string $shimDir;

    private string|false $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanDir = sys_get_temp_dir() . '/pa-queue-workers-' . bin2hex(random_bytes(4));
        mkdir($this->scanDir);

        $this->shimDir = sys_get_temp_dir() . '/pa-queue-s6-' . bin2hex(random_bytes(4));
        mkdir($this->shimDir);
        $this->path = getenv('PATH');
        putenv('PATH=' . $this->shimDir . ':' . $this->path);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        exec('rm -rf ' . escapeshellarg($this->scanDir) . ' ' . escapeshellarg($this->shimDir));
        parent::tearDown();
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function counts(): array
    {
        return [
            'the minimum' => [QueueWorkers::MIN, true],
            'the maximum' => [QueueWorkers::MAX, true],
            'zero is below the minimum' => [0, false],
            'negative is below the minimum' => [-1, false],
            'one past the maximum' => [QueueWorkers::MAX + 1, false],
        ];
    }

    #[DataProvider('counts')]
    public function test_it_takes_a_count_in_range(int $count, bool $acceptable): void
    {
        $this->assertSame($acceptable, QueueWorkers::badCount($count) === null, (string) $count);
    }

    /** Each worker holds 45-90 MB, so a small host starts with one. */
    public function test_the_default_is_one_worker_below_4_gb_and_two_above(): void
    {
        $this->assertSame(1, QueueWorkers::defaultFor("MemTotal:        3900696 kB\n"), 'a 3.8 GB host');
        $this->assertSame(2, QueueWorkers::defaultFor("MemTotal:       15982592 kB\n"), 'a 15 GB host');
        $this->assertSame(2, QueueWorkers::defaultFor(''), 'unreadable');
    }

    public function test_it_writes_one_service_per_worker(): void
    {
        QueueWorkers::writeServices(3, $this->scanDir);

        $this->assertSame(['queue-1', 'queue-2', 'queue-3'], $this->services());
        $run = $this->scanDir . '/queue-2/run';
        $this->assertSame(QueueWorkers::runScript(), file_get_contents($run));
        $this->assertTrue(is_executable($run));
    }

    /** Removing a directory is how s6 is told to stop that worker. */
    public function test_lowering_the_count_removes_the_highest_workers_only(): void
    {
        QueueWorkers::writeServices(10, $this->scanDir);
        // What s6-supervise leaves behind in a live service directory.
        mkdir($this->scanDir . '/queue-10/supervise');
        touch($this->scanDir . '/queue-10/supervise/status');
        file_put_contents($this->scanDir . '/queue-1/run', 'stale');

        QueueWorkers::writeServices(2, $this->scanDir);

        $this->assertSame(['queue-1', 'queue-2'], $this->services());
        $this->assertSame(QueueWorkers::runScript(), file_get_contents($this->scanDir . '/queue-1/run'), 'rewritten');
        // Hidden, not deleted: its supervisor keeps writing there until the job ends.
        $this->assertCount(1, glob($this->scanDir . '/.queue-10-*/supervise/status') ?: []);
    }

    public function test_a_worker_retired_twice_does_not_fail(): void
    {
        QueueWorkers::writeServices(3, $this->scanDir);
        QueueWorkers::writeServices(2, $this->scanDir);
        QueueWorkers::writeServices(3, $this->scanDir);
        QueueWorkers::writeServices(2, $this->scanDir);

        $this->assertSame(['queue-1', 'queue-2'], $this->services());
        $this->assertCount(2, glob($this->scanDir . '/.queue-3-*', GLOB_ONLYDIR) ?: [], 'both retirements kept');
    }

    public function test_the_static_services_are_left_alone(): void
    {
        mkdir($this->scanDir . '/nginx');

        QueueWorkers::writeServices(1, $this->scanDir);

        $this->assertDirectoryExists($this->scanDir . '/nginx');
    }

    /** Recycled between jobs, so a deploy's memory is not held until --memory. */
    public function test_a_worker_recycles_and_drops_root(): void
    {
        $script = QueueWorkers::runScript();

        $this->assertStringContainsString('--max-jobs=20', $script);
        $this->assertStringContainsString('--max-time=3600', $script);
        $this->assertStringContainsString('s6-setuidgid www-data', $script);
        $this->assertStringStartsWith("#!/bin/sh\n", $script);
    }

    public function test_nothing_is_running_without_s6(): void
    {
        QueueWorkers::writeServices(2, $this->scanDir);

        $this->assertNull(QueueWorkers::running($this->scanDir));
    }

    /** The state is root's until the run script opens it to www-data, before it leaves the service directory. */
    public function test_a_worker_lets_www_data_read_its_state(): void
    {
        $script = QueueWorkers::runScript();

        $this->assertStringContainsString("s6-svperms -g www-data . >/dev/null 2>&1\ncd /var/www/html", $script);
    }

    public function test_it_counts_the_workers_s6_reports_up(): void
    {
        $this->queueServices(2);
        $this->fakeS6('echo true');

        $this->assertSame(2, QueueWorkers::running($this->scanDir));
    }

    /** A worker started before its run script granted www-data the state: unknown, never down. */
    public function test_a_worker_it_cannot_read_is_unknown_not_down(): void
    {
        $this->queueServices(2);
        $this->fakeS6("echo 's6-svstat: fatal: unable to check: Permission denied' >&2; exit 111");

        $this->assertNull(QueueWorkers::running($this->scanDir));
    }

    public function test_a_stopped_worker_is_not_counted(): void
    {
        $this->queueServices(2);
        $this->fakeS6('echo false');

        $this->assertSame(0, QueueWorkers::running($this->scanDir));
    }

    /** Written by a rescan s6-svscan has not acted on yet: nothing to ask s6 about. */
    public function test_a_worker_s6_has_not_picked_up_yet_is_down(): void
    {
        $this->queueServices(2);
        QueueWorkers::writeServices(3, $this->scanDir);
        $this->fakeS6('echo true');

        $this->assertSame(2, QueueWorkers::running($this->scanDir));
    }

    /** A scan directory s6-svscan is running on, supervising $count worker services. */
    private function queueServices(int $count): void
    {
        mkdir($this->scanDir . '/.s6-svscan');
        QueueWorkers::writeServices($count, $this->scanDir);
        for ($i = 1; $i <= $count; $i++) {
            mkdir($this->scanDir . '/queue-' . $i . '/supervise');
        }
    }

    private function fakeS6(string $body): void
    {
        file_put_contents($this->shimDir . '/s6-svstat', "#!/bin/sh\n" . $body . "\n");
        chmod($this->shimDir . '/s6-svstat', 0755);
    }

    /** @return list<string> */
    private function services(): array
    {
        $names = array_map('basename', glob($this->scanDir . '/*', GLOB_ONLYDIR) ?: []);
        natsort($names);

        return array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, 'queue-')));
    }
}
