<?php

namespace Tests\Unit\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\TaskReconciler;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskReconcilerTest extends SqliteTaskTestCase
{
    private static int $jobSeq = 0;

    private function runningTask(
        string $jobType = 'App\\Jobs\\DeployProject',
        string $queue = 'default',
    ): Task {
        $task = Task::start(jobType: $jobType, queue: $queue, username: 'shop');
        $task->markRunning('h-' . ++self::$jobSeq);

        return $task->refresh();
    }

    /**
     * @param array<string, mixed> $latest
     */
    private function log(string $status, ?string $error = null, ?int $startedAt = null): array
    {
        return [
            'id' => 'd-1',
            'status' => $status,
            'stage' => 'running',
            'pid' => null,
            'started_at' => $startedAt ?? time(),
            'finished_at' => null,
            'error' => $error,
        ];
    }

    /** The queue says the job is gone. */
    private function gone(): callable
    {
        return static fn (Task $t): bool => false;
    }

    /** The queue says a worker still owes this job an answer. */
    private function queued(): callable
    {
        return static fn (Task $t): bool => true;
    }

    /** The queue could not be read. */
    private function unknown(): callable
    {
        return static fn (Task $t): ?bool => null;
    }

    // ---- a terminal deploy log is a real verdict -------------------------

    public function test_a_deploy_log_that_finished_completes_the_row(): void
    {
        $task = $this->runningTask();

        $this->assertSame(
            Task::STATUS_COMPLETED,
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_SUCCESS), $this->gone())
        );
    }

    public function test_a_partial_deploy_counts_as_completed(): void
    {
        $task = $this->runningTask();

        $this->assertSame(
            Task::STATUS_COMPLETED,
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_PARTIAL), $this->gone())
        );
    }

    public function test_a_deploy_log_that_failed_fails_the_row(): void
    {
        $task = $this->runningTask();

        $this->assertSame(
            Task::STATUS_FAILED,
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_FAILED, 'boom'), $this->gone())
        );
    }

    public function test_a_terminal_log_wins_even_while_the_job_is_still_reserved(): void
    {
        // The work is over; the row merely never heard. A log that says so is
        // adopted whether or not a worker still holds the reservation.
        $task = $this->runningTask();

        $this->assertSame(
            Task::STATUS_COMPLETED,
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_SUCCESS), $this->queued())
        );
    }

    // ---- the queue decides the rest --------------------------------------

    public function test_a_job_the_queue_no_longer_holds_is_cancelled_with_no_log(): void
    {
        // The reboot case: the work never wrote a log, and no worker took it.
        $task = $this->runningTask();

        $this->assertSame(Task::STATUS_CANCELLED, TaskReconciler::decide($task, null, $this->gone()));
    }

    public function test_a_job_the_queue_still_holds_is_left_alone(): void
    {
        // This is the regression that mattered: a deploy midway through its
        // rollback has already deleted its deploy log, and an earlier version
        // of this class read that as death and cancelled it.
        $task = $this->runningTask();

        $this->assertNull(TaskReconciler::decide($task, null, $this->queued()));
    }

    public function test_a_job_the_queue_still_holds_is_left_alone_mid_deploy(): void
    {
        $task = $this->runningTask();

        $this->assertNull(
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_RUNNING), $this->queued())
        );
    }

    public function test_an_unreadable_queue_is_not_evidence_of_death(): void
    {
        $task = $this->runningTask();

        $this->assertNull(TaskReconciler::decide($task, null, $this->unknown()));
        $this->assertNull(
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_RUNNING), $this->unknown())
        );
    }

    public function test_a_job_that_left_the_queue_with_a_running_log_is_cancelled(): void
    {
        // Still mid-deploy by its own log, but nothing in the queue can ever
        // finish it, so it would otherwise read `running` for good.
        $task = $this->runningTask();

        $this->assertSame(
            Task::STATUS_CANCELLED,
            TaskReconciler::decide($task, $this->log(DeployLogger::STATUS_RUNNING), $this->gone())
        );
    }

    // ---- the sweep -------------------------------------------------------

    public function test_reconcile_retires_only_the_orphan_and_is_dry_run_safe(): void
    {
        $orphan = $this->runningTask();

        $dry = TaskReconciler::reconcile(true, 0, $this->gone());
        $this->assertSame([$orphan->id], $dry);
        $this->assertSame(Task::STATUS_RUNNING, $orphan->refresh()->status);

        $retired = TaskReconciler::reconcile(false, 0, $this->gone());
        $this->assertSame([$orphan->id], $retired);
        $this->assertSame(Task::STATUS_CANCELLED, $orphan->refresh()->status);
        $this->assertNotNull($orphan->cancelled_at);
        $this->assertStringContainsString('stopped without finishing', $orphan->details['reconciled']);
    }

    public function test_reconcile_leaves_queued_jobs_alone(): void
    {
        $live = $this->runningTask();

        $this->assertSame([], TaskReconciler::reconcile(false, 0, $this->queued()));
        $this->assertSame(Task::STATUS_RUNNING, $live->refresh()->status);
    }

    public function test_a_recent_task_is_not_touched(): void
    {
        // The grace period keeps the sweep from racing a deploy that has
        // marked itself running but whose job has not been pushed yet.
        $orphan = $this->runningTask();

        $this->assertSame([], TaskReconciler::reconcile(false, 3600, $this->gone()));
        $this->assertSame(Task::STATUS_RUNNING, $orphan->refresh()->status);
    }

    public function test_the_sweep_leaves_terminal_tasks_alone(): void
    {
        $done = $this->runningTask();
        $done->markCompleted();

        $this->assertSame([], TaskReconciler::reconcile(false, 0, $this->gone()));
        $this->assertSame(Task::STATUS_COMPLETED, $done->refresh()->status);
    }

    public function test_a_non_deploy_job_is_reconciled_by_the_queue_too(): void
    {
        // It has no deploy log, but the queue still knows whether a worker is
        // going to finish it -- which is all this needs.
        $backup = $this->runningTask('App\\Jobs\\CreateBackup');

        $this->assertNull(TaskReconciler::decide($backup, null, $this->queued()));
        $this->assertSame(Task::STATUS_CANCELLED, TaskReconciler::decide($backup, null, $this->gone()));
    }

    // ---- the real queue read ------------------------------------------
    //
    // Every test above injects a queue verdict. These read a real `jobs`
    // table, because the uuid lives inside the payload and a lookup keyed by
    // the bare uuid once retired nothing at all.

    private function createJobsTable(): void
    {
        $migration = require base_path('database/migrations/2026_09_15_000000_create_jobs_table.php');
        $migration->up();
    }

    /** Push a job the way the engine does, and return the uuid Laravel gave it. */
    private function pushJob(string $queue = 'default'): string
    {
        $manager = app('queue');
        $manager->connection('database')->push(new \Illuminate\Queue\CallQueuedClosure(
            new \Laravel\SerializableClosure\SerializableClosure(static fn () => null),
        ), '', $queue);

        $payload = json_decode((string) DB::table('jobs')->orderByDesc('id')->value('payload'), true);

        return (string) $payload['uuid'];
    }

    private function runningTaskFor(string $uuid): Task
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default', username: 'shop');
        $task->markRunning($uuid);

        return $task->refresh();
    }

    public function test_a_pending_job_is_not_retired(): void
    {
        $this->createJobsTable();
        $task = $this->runningTaskFor($this->pushJob());

        $check = TaskReconciler::queueCheck();

        $this->assertTrue($check($task));
        $this->assertNull(TaskReconciler::decide($task, null, $check));
    }

    public function test_a_job_reserved_by_a_worker_is_not_retired(): void
    {
        $this->createJobsTable();
        $task = $this->runningTaskFor($this->pushJob());
        DB::table('jobs')->update(['reserved_at' => time(), 'attempts' => 1]);

        $this->assertTrue(TaskReconciler::queueCheck()($task));
    }

    public function test_a_job_gone_from_the_table_is_retired(): void
    {
        // The case that must work: the deploy died with its worker, nothing
        // finished it, and the row has to be retired.
        $this->createJobsTable();
        $this->pushJob();
        $task = $this->runningTask();

        $check = TaskReconciler::queueCheck();

        $this->assertFalse($check($task));
        $this->assertSame(Task::STATUS_CANCELLED, TaskReconciler::decide($task, null, $check));
    }

    public function test_a_job_on_another_queue_is_not_this_one(): void
    {
        $this->createJobsTable();
        $task = $this->runningTaskFor($this->pushJob('backups'));

        $this->assertFalse(TaskReconciler::queueCheck()($task));
    }

    public function test_a_nested_uuid_is_still_the_same_job(): void
    {
        $task = $this->runningTask();
        $member = json_encode([
            'displayName' => 'App\\Jobs\\DeployProject',
            'data' => ['uuid' => (string) $task->job_id],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(TaskReconciler::membersCarryJob([$member], (string) $task->job_id));
    }

    public function test_a_garbage_member_is_not_a_match(): void
    {
        $this->assertFalse(TaskReconciler::membersCarryJob(['not json'], 'job-1'));
        $this->assertFalse(TaskReconciler::membersCarryJob(['[]'], 'job-1'));
        $this->assertFalse(TaskReconciler::membersCarryJob([], 'job-1'));
    }

    public function test_an_unreadable_queue_leaves_the_task_alone(): void
    {
        // No `jobs` table: the read throws, and "cannot tell" is not death.
        $task = $this->runningTask();

        $check = TaskReconciler::queueCheck();

        $this->assertNull($check($task));
        $this->assertNull(TaskReconciler::decide($task, null, $check));
    }
}
