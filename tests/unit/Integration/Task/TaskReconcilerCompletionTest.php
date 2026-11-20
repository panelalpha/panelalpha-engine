<?php

namespace Tests\Integration\Task;

use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\TaskReconciler;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/** A deploy whose log finished well completes its row; a row with no account to read a log for is left alone. */
class TaskReconcilerCompletionTest extends SqliteTaskTestCase
{
    public function test_a_running_rebuild_whose_recorded_deploy_succeeded_is_completed_and_logged(): void
    {
        $username = 'done-'.bin2hex(random_bytes(4));
        Log::spy();
        try {
            $logger = DeployLogger::start($username);
            $id = $logger->getDeployId();
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $username);
            $task->markRunning('h-done');
            $task->details = ['deploy_id' => $id];
            $task->save();

            $this->assertSame(
                [TaskReconciler::RETIRED_ORPHANED => [(int) $task->id], TaskReconciler::RETIRED_CANCELLED => []],
                TaskReconciler::reconcile(isPending: static fn (Task $t): bool => true)
            );
            $this->assertSame(Task::STATUS_COMPLETED, $task->refresh()->status);
            Log::shouldHaveReceived('warning')->with('Task '.$task->id.' for '.$username.' was reconciled to completed from its deploy log.');
        } finally {
            DeployLogger::deleteUserLogs($username);
        }
    }

    public function test_a_deploy_row_without_a_username_is_left_to_the_queue(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default', username: '');
        $task->markRunning('h-anon');

        $this->assertSame(
            [TaskReconciler::RETIRED_ORPHANED => [], TaskReconciler::RETIRED_CANCELLED => []],
            TaskReconciler::reconcile(isPending: static fn (Task $t): bool => true)
        );
        $this->assertSame(Task::STATUS_RUNNING, $task->refresh()->status);
    }
}
