<?php

namespace Tests\Unit\Support;

use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Support\JobBudget;
use App\Support\QueueWorkers;
use Tests\TestCase;

/**
 * engine#776: one job budget, and every cap below it. A job's `$timeout`
 * SIGKILLs its worker, so an inner command capped at the same value was killed
 * with no cleanup.
 */
class JobBudgetTest extends TestCase
{
    public function test_the_caps_are_ordered_so_a_command_fails_before_its_job(): void
    {
        $this->assertLessThan(JobBudget::SECONDS, JobBudget::COMMAND_SECONDS, 'a command must fail before its job');
        $this->assertLessThan(JobBudget::WORKER_SECONDS, JobBudget::SECONDS, 'a job must fail before its worker');
        $this->assertLessThan(JobBudget::RETRY_AFTER_SECONDS, JobBudget::WORKER_SECONDS, 'the worker must finish before the retry');
    }

    public function test_the_jobs_use_the_budget(): void
    {
        $this->assertSame(JobBudget::SECONDS, (new CreateBackup(1))->timeout);
        $this->assertSame(JobBudget::SECONDS, (new RestoreBackup(1))->timeout);
    }

    public function test_the_worker_and_the_queue_read_the_same_numbers(): void
    {
        $this->assertStringContainsString('--timeout=' . JobBudget::WORKER_SECONDS, QueueWorkers::runScript());
        $this->assertSame(JobBudget::RETRY_AFTER_SECONDS, config('queue.connections.database.retry_after'));
    }

    public function test_the_inner_command_caps_sit_below_the_budget(): void
    {
        $mysql = (new \ReflectionClass(\App\System\Services\Mysql::class))->getConstant('DB_COMMAND_TIMEOUT');
        $volume = (new \ReflectionClass(\App\System\Project\Dind\VolumeArchive::class))->getConstant('COMPOSE_TIMEOUT');

        $this->assertSame(JobBudget::COMMAND_SECONDS, $mysql);
        $this->assertSame(JobBudget::COMMAND_SECONDS, $volume);
    }
}
