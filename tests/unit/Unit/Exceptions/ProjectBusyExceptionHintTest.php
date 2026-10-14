<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateBackup;
use App\Jobs\PushState;
use App\Jobs\RebuildProject;
use App\Jobs\RenameMysqlDatabase;
use App\Models\Task;
use PHPUnit\Framework\TestCase;

/** The busy hint must not send a caller to a cancel that answers 409. */
class ProjectBusyExceptionHintTest extends TestCase
{
    public function test_a_running_backup_is_waited_for_not_cancelled(): void
    {
        $message = ProjectBusyException::taskRunning('alice', $this->task(CreateBackup::class, Task::STATUS_RUNNING))->getMessage();

        $this->assertSame("Project 'alice' is busy: backup task 36 is running. Wait for it to finish.", $message);
    }

    public function test_a_queued_backup_and_a_running_rebuild_can_still_be_cancelled(): void
    {
        $queued = ProjectBusyException::taskRunning('alice', $this->task(CreateBackup::class, Task::STATUS_QUEUED))->getMessage();
        $rebuild = ProjectBusyException::taskRunning('alice', $this->task(RebuildProject::class, Task::STATUS_RUNNING))->getMessage();

        $this->assertStringContainsString('Cancel it (POST /tasks/36/cancel)', $queued);
        $this->assertStringContainsString('Cancel it (POST /tasks/36/cancel)', $rebuild);
    }

    public function test_a_queued_task_is_called_queued_not_running(): void
    {
        $message = ProjectBusyException::taskRunning('alice', $this->task(RebuildProject::class, Task::STATUS_QUEUED))->getMessage();

        $this->assertSame(
            "Project 'alice' is busy: rebuild task 36 is queued. Cancel it (POST /tasks/36/cancel) or wait for it to finish.",
            $message,
        );
    }

    public function test_a_running_push_is_named_and_waited_for(): void
    {
        $message = ProjectBusyException::taskRunning('alice', $this->task(PushState::class, Task::STATUS_RUNNING))->getMessage();

        $this->assertSame("Project 'alice' is busy: push task 36 is running. Wait for it to finish.", $message);
    }

    public function test_a_running_database_rename_is_named_and_waited_for(): void
    {
        $message = ProjectBusyException::taskRunning('alice', $this->task(RenameMysqlDatabase::class, Task::STATUS_RUNNING))->getMessage();

        $this->assertSame("Project 'alice' is busy: database rename task 36 is running. Wait for it to finish.", $message);
    }

    private function task(string $jobType, string $status): Task
    {
        $task = new Task();
        $task->forceFill(['id' => 36, 'job_type' => $jobType, 'status' => $status, 'pid' => null, 'details' => []]);

        return $task;
    }
}
