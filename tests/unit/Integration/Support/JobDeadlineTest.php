<?php

namespace Tests\Integration\Support;

use App\Support\JobBudget;
use App\Support\JobDeadline;
use Tests\TestCase;

/**
 * A backup or restore job's steps share one window, so together they cannot
 * outlast the job and be killed while the app is stopped.
 */
class JobDeadlineTest extends TestCase
{
    public function test_a_fresh_deadline_gives_a_step_the_single_command_window(): void
    {
        $deadline = JobDeadline::startingNow();

        $this->assertFalse($deadline->expired());
        $this->assertSame(JobBudget::COMMAND_SECONDS, $deadline->stepSeconds());
    }

    public function test_a_step_gets_only_what_is_left_of_the_deadline(): void
    {
        $deadline = new JobDeadline(microtime(true) + 120);

        $this->assertSame(120, $deadline->stepSeconds());
    }

    public function test_an_expired_deadline_has_nothing_left(): void
    {
        $deadline = new JobDeadline(microtime(true) - 5);

        $this->assertTrue($deadline->expired());
    }
}
