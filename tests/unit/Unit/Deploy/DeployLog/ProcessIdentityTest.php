<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\ProcessIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Telling one process from the next one that reused its pid.
 *
 * A cancel request kills the pid recorded in latest.json. Between the deploy
 * writing that pid and somebody pressing cancel, the process may have exited
 * and the kernel handed the number to something else - so the start time is
 * recorded alongside it and checked before any signal is sent. Without that
 * check, cancelling a finished deploy kills whatever now holds the number.
 */
class ProcessIdentityTest extends TestCase
{
    public function test_a_running_process_has_a_start_time(): void
    {
        $startTime = ProcessIdentity::startTime(getmypid());

        $this->assertNotNull($startTime);
        $this->assertTrue(ctype_digit($startTime));
    }

    public function test_the_start_time_is_stable_across_reads(): void
    {
        // It has to be, or every cancel would look like a pid reuse.
        $this->assertSame(
            ProcessIdentity::startTime(getmypid()),
            ProcessIdentity::startTime(getmypid())
        );
    }

    public function test_a_process_that_is_not_there_has_no_start_time(): void
    {
        $this->assertNull(ProcessIdentity::startTime(0));
        $this->assertNull(ProcessIdentity::startTime(-1));
        $this->assertNull(ProcessIdentity::startTime(4194304));
    }

    public function test_a_pid_with_its_own_start_time_is_still_running(): void
    {
        $pid = getmypid();

        $this->assertTrue(ProcessIdentity::isStillRunning($pid, ProcessIdentity::startTime($pid)));
    }

    public function test_the_same_pid_with_a_different_start_time_is_a_different_process(): void
    {
        // The case the whole class exists for: the recorded process exited
        // and something else was given its number.
        $this->assertFalse(ProcessIdentity::isStillRunning(getmypid(), '1'));
    }

    public function test_a_record_with_nothing_to_compare_is_not_running(): void
    {
        // An older latest.json with a pid but no start time. Refusing to
        // signal is the safe answer.
        $this->assertFalse(ProcessIdentity::isStillRunning(getmypid(), null));
        $this->assertFalse(ProcessIdentity::isStillRunning(getmypid(), 12345));
    }

    public function test_a_record_with_no_usable_pid_is_not_running(): void
    {
        $this->assertFalse(ProcessIdentity::isStillRunning(null, '1'));
        $this->assertFalse(ProcessIdentity::isStillRunning(0, '1'));
        $this->assertFalse(ProcessIdentity::isStillRunning('123', '1'));
    }

    public function test_a_dead_pid_is_not_running(): void
    {
        $pid = ProcessIdentity::startTime(4194303) === null ? 4194303 : 4194302;

        $this->assertFalse(ProcessIdentity::isStillRunning($pid, '1'));
    }

    public function test_a_process_name_containing_spaces_does_not_confuse_the_parse(): void
    {
        // /proc/pid/stat's comm field is parenthesised and may contain spaces
        // and parentheses of its own; counting fields from the left would
        // read the wrong number for a process called "(my proc) x".
        $pid = getmypid();
        $stat = (string) @file_get_contents("/proc/{$pid}/stat");
        $fields = preg_split('/\s+/', trim(substr($stat, (int) strrpos($stat, ')') + 1))) ?: [];

        $this->assertSame($fields[19] ?? null, ProcessIdentity::startTime($pid));
    }
}
