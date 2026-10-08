<?php

namespace Tests\Unit\Task;

use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
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

    /**
     * warpgate: core restarted under the worker, so the database queue still
     * holds the job as reserved (until retry_after, a day) and the queue says
     * "pending" -- while nothing holds the account's deploy lock. Once the
     * dead deploy's log is closed, the task adopts its verdict.
     */
    public function test_a_reserved_deploy_whose_worker_died_fails_once_its_log_is_closed(): void
    {
        $username = 'reserved-' . bin2hex(random_bytes(4));
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default', username: $username);
        $task->markRunning('h-' . ++self::$jobSeq);
        $task->started_at = now()->subMinutes(10);
        $task->save();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        unset($logger);
        gc_collect_cycles();

        try {
            $this->assertSame([], TaskReconciler::reconcile(isPending: $this->queued()), 'running log, reserved job: left alone');

            $this->assertContains($username, DeployLogger::settleOrphanedDeploys());
            $this->assertSame([(int) $task->id], TaskReconciler::reconcile(isPending: $this->queued()));

            $task->refresh();
            $this->assertSame(Task::STATUS_FAILED, $task->status);
            $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $task->details['error'] ?? null);
        } finally {
            DeployLogger::deleteUserLogs($username);
        }
    }

    // ---- a rebuild resumes a log it did not start; its worker may die ----

    /** A finished deploy, its log backdated as a create's waiting log would be. */
    private function finishedLogStartedAnHourAgo(string $username, string $status, string $error): string
    {
        $logger = DeployLogger::start($username);
        $id = $logger->getDeployId();
        $logger->finish($status, $error);
        $paths = new DeployStatus(new DeployLogPaths($username));
        $paths->write(array_merge($paths->read() ?? [], ['started_at' => time() - 3600]));

        return $id;
    }

    private function runningRebuild(string $username, array $details): Task
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);
        $task->markRunning('h-' . ++self::$jobSeq);
        $task->started_at = now()->subMinutes(10);
        $task->details = $details;
        $task->save();

        return $task->refresh();
    }

    public function test_a_rebuild_is_judged_by_the_deploy_it_recorded_however_old_that_log_is(): void
    {
        $username = 'rec-' . bin2hex(random_bytes(4));
        try {
            $id = $this->finishedLogStartedAnHourAgo($username, DeployLogger::STATUS_FAILED, 'Failed to start app: boom');
            $task = $this->runningRebuild($username, ['deploy_id' => $id]);

            $this->assertSame([(int) $task->id], TaskReconciler::reconcile(isPending: $this->queued()));
            $this->assertSame(Task::STATUS_FAILED, $task->refresh()->status);
            $this->assertSame('Failed to start app: boom', $task->details['error']);
        } finally {
            DeployLogger::deleteUserLogs($username);
        }
    }

    public function test_a_rebuild_is_not_judged_by_a_deploy_it_did_not_record(): void
    {
        $username = 'rec-' . bin2hex(random_bytes(4));
        try {
            $this->finishedLogStartedAnHourAgo($username, DeployLogger::STATUS_FAILED, 'someone else');
            $task = $this->runningRebuild($username, ['deploy_id' => 'another-deploy']);

            $this->assertSame([], TaskReconciler::reconcile(isPending: $this->queued()));
            $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status);
        } finally {
            DeployLogger::deleteUserLogs($username);
        }
    }

    /** The killed job's queue row stays reserved for a day; its dead worker says it is gone. */
    public function test_a_task_whose_worker_died_is_retired_though_its_job_is_still_reserved(): void
    {
        $task = $this->runningRebuild('shop', [Task::WORKER => ['pid' => getmypid(), 'start' => '0']]);

        $this->assertTrue(TaskReconciler::workerGone($task));
        $this->assertSame(Task::STATUS_CANCELLED, TaskReconciler::decide($task, null, $this->queued()));
    }

    public function test_a_task_whose_worker_is_alive_waits_for_its_queue_row(): void
    {
        $pid = getmypid();
        $task = $this->runningRebuild('shop', [Task::WORKER => ['pid' => $pid, 'start' => ProcessIdentity::startTime($pid)]]);

        $this->assertFalse(TaskReconciler::workerGone($task));
        $this->assertNull(TaskReconciler::decide($task, null, $this->queued()));
        $this->assertSame(Task::STATUS_CANCELLED, TaskReconciler::decide($task, null, $this->gone()));
    }

    public function test_a_worker_that_was_not_recorded_is_not_evidence_of_anything(): void
    {
        $task = $this->runningRebuild('shop', []);

        $this->assertFalse(TaskReconciler::workerGone($task));
        $this->assertNull(TaskReconciler::decide($task, null, $this->queued()));
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
