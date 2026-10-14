<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\HostBuildSlot;
use Tests\TestCase;

/**
 * The slot that keeps host builds from summing past the host.
 *
 * Each build is sized at a third of MemTotal, which is only safe one at a
 * time -- and Horizon runs eight workers, with DeployLock scoped per account.
 */
class HostBuildSlotTest extends TestCase
{
    public function test_it_returns_what_the_build_returned(): void
    {
        $this->assertSame('built', HostBuildSlot::run(static fn (): string => 'built'));
    }

    public function test_the_slot_is_released_when_the_build_throws(): void
    {
        try {
            HostBuildSlot::run(static fn () => throw new \RuntimeException('build failed'));
            $this->fail('the exception should reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('build failed', $e->getMessage());
        }

        // Still takeable: a failed build must not strand the slot for the rest
        // of the engine's life.
        $this->assertSame('after', HostBuildSlot::run(static fn (): string => 'after'));
    }

    /**
     * A second builder waits for the first rather than running beside it.
     *
     * Forked, because flock is a property of the file across *processes* --
     * which is exactly the shape Horizon's eight workers have, and the one a
     * single-process test cannot show.
     */
    public function test_a_second_build_waits_for_the_first(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'could not fork');

        if ($pid === 0) {
            HostBuildSlot::run(static function (): void {
                usleep(400000);
            });
            exit(0);
        }

        usleep(80000);
        $startedWaiting = microtime(true);
        $waitedFor = null;
        HostBuildSlot::run(static fn () => null, static function () use (&$waitedFor): void {
            $waitedFor = true;
        });
        $elapsed = microtime(true) - $startedWaiting;
        pcntl_waitpid($pid, $status);

        $this->assertTrue($waitedFor, 'the second build should have been told it was queued');
        $this->assertGreaterThan(0.2, $elapsed, 'the second build ran beside the first');
    }
}
