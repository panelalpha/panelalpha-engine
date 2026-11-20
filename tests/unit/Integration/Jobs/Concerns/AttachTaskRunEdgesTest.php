<?php

namespace Tests\Integration\Jobs\Concerns;

use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Integration\Task\SqliteTaskTestCase;
use Tests\Integration\Task\Support\StubTaskJob;

/** runTask() when the task changes under the work: cancelled, deleted, or its log cannot be trimmed. */
class AttachTaskRunEdgesTest extends SqliteTaskTestCase
{
    public function test_a_task_cancelled_while_running_stops_the_work_at_the_next_check(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = (new StubTaskJob())->attachTask($task);
        $reached = false;

        $result = $job->runTask(function () use ($job, $task, &$reached) {
            Task::query()->whereKey($task->id)->update(['status' => Task::STATUS_CANCELLED]);
            $job->throwIfCancelled();
            $reached = true;

            return 'done';
        });

        $this->assertNull($result);
        $this->assertFalse($reached);
        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_task_deleted_while_running_still_returns_the_result(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = (new StubTaskJob())->attachTask($task);

        $result = $job->runTask(function () use ($task) {
            Task::query()->whereKey($task->id)->delete();

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertNull(Task::query()->find($task->id));
    }

    public function test_a_log_trim_that_fails_is_only_logged(): void
    {
        $task = Task::start(jobType: StubTaskJob::class, queue: 'default');
        $job = (new StubTaskJob())->attachTask($task);
        Log::spy();

        $result = $job->runTask(function () {
            Schema::drop((new TaskLog())->getTable());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Task log trim failed: '),
        )->once();
    }
}
