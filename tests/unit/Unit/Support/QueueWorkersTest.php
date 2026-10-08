<?php

namespace Tests\Unit\Support;

use App\Support\QueueWorkers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueWorkersTest extends TestCase
{
    private string $scanDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanDir = sys_get_temp_dir() . '/pa-queue-workers-' . bin2hex(random_bytes(4));
        mkdir($this->scanDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->scanDir));
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

    /** @return list<string> */
    private function services(): array
    {
        $names = array_map('basename', glob($this->scanDir . '/*', GLOB_ONLYDIR) ?: []);
        natsort($names);

        return array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, 'queue-')));
    }
}
