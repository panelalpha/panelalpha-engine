<?php

namespace Tests\Unit\Task;

use App\Exceptions\TaskCancelledException;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Unit\Task\Support\StubTaskJob;

class AttachTaskTest extends SqliteTaskTestCase
{
    public function test_attach_task_sets_task_id_and_rejects_a_second_call(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);

        $this->assertSame($task->id, $job->taskId);
        $this->expectException(LogicException::class);
        $job->attachTask($task);
    }

    public function test_attach_task_copies_job_queue_onto_the_row(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->onQueue('deploys');
        $job->attachTask($task);

        $this->assertSame('deploys', $task->refresh()->queue);
    }

    public function test_unattached_methods_are_noops_and_run_task_still_runs_work(): void
    {
        $job = new StubTaskJob();
        $called = false;

        $this->assertNull($job->task());
        $this->assertFalse($job->isCancelled());
        $job->throwIfCancelled();
        $job->markRunning();
        $job->markCompleted();
        $job->markFailed(new RuntimeException('nope'));
        $job->markCancelled();
        $job->setTaskPid(1);
        $job->logTask('should not persist');

        $result = $job->runTask(function () use (&$called) {
            $called = true;
            return 42;
        });

        $this->assertTrue($called);
        $this->assertSame(42, $result);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, TaskLog::count());
    }

    public function test_missing_task_row_is_treated_as_unattached(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);
        $task->delete();

        $called = false;
        $result = $job->runTask(function () use (&$called) {
            $called = true;
            return 'ok';
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $result);
        $this->assertNull($job->task());
        $this->assertFalse($job->isCancelled());
    }

    public function test_run_task_marks_completed_on_success(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = $this->jobWithQueueId($task, 'h-1');

        $this->assertSame('ok', $job->handle());
        $task->refresh();
        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertSame('h-1', $task->job_id);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->completed_at);
    }

    public function test_run_task_marks_failed_and_rethrows(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = $this->jobWithQueueId($task, 'h-2');

        try {
            $job->runTask(function () {
                throw new RuntimeException('disk full');
            });
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $task->refresh();
        $this->assertSame(Task::STATUS_FAILED, $task->status);
        $this->assertSame('disk full', $task->details['error']);
    }

    public function test_run_task_skips_work_when_already_cancelled(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $task->markCancelled();
        $job = $this->jobWithQueueId($task, 'h-3');
        $called = false;

        $result = $job->runTask(function () use (&$called) {
            $called = true;
        });

        $this->assertNull($result);
        $this->assertFalse($called);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertNull($task->job_id);
    }

    /**
     * A worker killed right after it took the task has still named itself on
     * it, so the reconciler and a delete can tell the task stopped.
     */
    public function test_the_worker_is_recorded_by_the_write_that_takes_the_task(): void
    {
        $pid = getmypid();
        if (!is_int($pid) || !is_readable('/proc/' . $pid . '/stat')) {
            $this->markTestSkipped('/proc is required to record the worker');
        }
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = $this->jobWithQueueId($task, 'h-5');
        $killed = false;
        DB::listen(static function (QueryExecuted $query) use (&$killed): void {
            if (!$killed && str_starts_with($query->sql, 'update "tasks"')) {
                $killed = true;
                throw new RuntimeException('worker killed');
            }
        });

        try {
            $job->markRunning();
        } catch (RuntimeException) {
            // Nothing after that write ran.
        }

        $task->refresh();
        $this->assertTrue($killed);
        $this->assertSame(Task::STATUS_RUNNING, $task->status);
        $this->assertSame($pid, $task->details[Task::WORKER]['pid'] ?? null);
    }

    /** The worker read the task while it was queued; a cancel landed before it took it. */
    public function test_a_cancel_written_after_the_worker_read_the_task_stays(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default', details: ['action' => 'rebuild']);
        $job = new class (Task::find($task->id)) extends StubTaskJob {
            public function __construct(private ?Task $read)
            {
            }

            public function task(): ?Task
            {
                $copy = $this->read ?? parent::task();
                $this->read = null;

                return $copy;
            }
        };
        $job->attachTask($task);
        Task::find($task->id)->markCancelled();

        $job->markRunning();
        $called = false;
        $job->runTask(function () use (&$called): void {
            $called = true;
        });

        $task->refresh();
        $this->assertFalse($called);
        $this->assertSame(Task::STATUS_CANCELLED, $task->status);
        $this->assertNull($task->started_at);
        $this->assertSame(['action' => 'rebuild'], $task->details, 'no worker written onto it');
    }

    public function test_run_task_cancel_exception_does_not_rethrow(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = $this->jobWithQueueId($task, 'h-4');

        $result = $job->runTask(function () use ($job) {
            $job->throwIfCancelled();
            throw new TaskCancelledException();
        });

        $this->assertNull($result);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
        $this->assertNull($task->failed_at);
    }

    public function test_log_task_inserts_non_empty_lines(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);

        $job->logTask('');
        $job->logTask('clone started');

        $this->assertSame(1, TaskLog::count());
        $this->assertSame('clone started', TaskLog::first()->log);
    }

    public function test_run_task_drops_dim_lines_once_terminal(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);

        $job->runTask(function () use ($job): void {
            TaskLog::create([
                'task_id' => $job->taskId,
                'log' => json_encode([
                    'ts' => time(),
                    'stage' => 'running',
                    'level' => 'dim',
                    'msg' => 'npm notice',
                ]),
            ]);
            TaskLog::create([
                'task_id' => $job->taskId,
                'log' => json_encode([
                    'ts' => time(),
                    'stage' => 'running',
                    'level' => 'info',
                    'msg' => 'done',
                ]),
            ]);
        });

        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        $this->assertSame(1, TaskLog::count());
        $decoded = json_decode(TaskLog::first()->log, true);
        $this->assertSame('done', $decoded['msg']);
    }

    public function test_set_task_pid_null_clears_identity(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);
        $task->setPid(99, '1');

        $job->setTaskPid(null);
        $task->refresh();
        $this->assertNull($task->pid);
        $this->assertNull($task->pid_start_time);
    }

    public function test_set_task_pid_stores_start_time_when_proc_is_readable(): void
    {
        $pid = getmypid();
        if (!is_int($pid) || !is_readable('/proc/' . $pid . '/stat')) {
            $this->markTestSkipped('/proc is required to record pid_start_time');
        }

        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = new StubTaskJob();
        $job->attachTask($task);
        $job->setTaskPid($pid);

        $task->refresh();
        $this->assertSame($pid, $task->pid);
        $this->assertNotNull($task->pid_start_time);
        $this->assertTrue(\App\Lib\Deploy\DeployLog\ProcessIdentity::isStillRunning($task->pid, $task->pid_start_time));
    }

    private function jobWithQueueId(Task $task, string $jobId): StubTaskJob
    {
        $job = new StubTaskJob();
        $job->attachTask($task);
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn($jobId);
        $job->setJob($queueJob);

        return $job;
    }
}
