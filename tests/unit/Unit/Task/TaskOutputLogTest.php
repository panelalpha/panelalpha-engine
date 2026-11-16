<?php

namespace Tests\Unit\Task;

use App\Lib\Task\TaskOutputLog;
use PHPUnit\Framework\TestCase;

/** Raw build output into a task's log: the first piece at once, the rest in batches. */
class TaskOutputLogTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    public function test_the_first_chunk_is_written_at_once_and_later_ones_wait_for_a_flush(): void
    {
        $log = $this->log();

        $log("step 1\n");
        $log("step 2\n");
        $log("step 3\n");
        $log->flush();

        $this->assertSame(['step 1', "step 2\nstep 3"], $this->written);
    }

    public function test_four_kilobytes_are_written_without_waiting(): void
    {
        $log = $this->log();
        $log('start');

        $log(str_repeat('x', 4095));
        $this->assertCount(1, $this->written);
        $log('y');

        $this->assertSame(['start', str_repeat('x', 4095).'y'], $this->written);
    }

    public function test_a_flush_with_nothing_pending_hands_over_an_empty_entry(): void
    {
        $log = $this->log();

        $log->flush();

        $this->assertSame([''], $this->written);
    }

    private function log(): TaskOutputLog
    {
        return new TaskOutputLog(function (string $entry): void {
            $this->written[] = $entry;
        });
    }
}
