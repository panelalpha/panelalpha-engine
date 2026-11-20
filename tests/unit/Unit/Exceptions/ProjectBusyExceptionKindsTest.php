<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ProjectBusyException;
use App\Jobs\DeleteBackup;
use App\Jobs\RebuildProject;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;
use PHPUnit\Framework\TestCase;

/** A refusal names the task in the way the caller started it. */
class ProjectBusyExceptionKindsTest extends TestCase
{
    public function test_a_rebuild_that_attaches_a_source_is_a_source_attach(): void
    {
        $task = $this->task(RebuildProject::class, ['action' => ProjectRebuild::ATTACH_SOURCE]);

        $this->assertSame('source attach task 51 is queued', ProjectBusyException::describe($task));
    }

    public function test_a_backup_delete_is_named_as_one(): void
    {
        $this->assertSame('backup delete task 51 is queued', ProjectBusyException::describe($this->task(DeleteBackup::class)));
    }

    /** @param array<string, string> $details */
    private function task(string $jobType, array $details = []): Task
    {
        $task = new Task();
        $task->forceFill(['id' => 51, 'job_type' => $jobType, 'status' => Task::STATUS_QUEUED, 'pid' => null, 'details' => $details]);

        return $task;
    }
}
