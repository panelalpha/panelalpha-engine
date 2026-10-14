<?php

namespace Tests\Unit\Deploy;

use App\Jobs\DeployProject;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Unit\Task\SqliteTaskTestCase;

class DeployProjectJobTest extends SqliteTaskTestCase
{
    public function test_job_has_single_try_two_hour_timeout_and_default_queue(): void
    {
        $job = new DeployProject('acme', ['upgrade' => []]);

        $this->assertSame(1, $job->tries);
        $this->assertSame(7200, $job->timeout);
        $this->assertSame('acme', $job->username);
        $this->assertSame(['upgrade' => []], $job->stages);
        $this->assertSame('default', $job->queue);
    }

    public function test_dispatch_chain_attaches_task_on_default_queue(): void
    {
        Queue::fake();

        $task = Task::start(
            jobType: DeployProject::class,
            queue: 'default',
            username: 'acme',
            details: ['username' => 'acme', 'domain' => 'acme.test', 'action' => 'deploy'],
        );
        DeployProject::dispatch('acme', null)->attachTask($task);

        $this->assertSame(DeployProject::class, $task->job_type);
        $this->assertSame('default', $task->queue);
        $this->assertSame(Task::STATUS_QUEUED, $task->status);

        Queue::assertPushed(DeployProject::class, function (DeployProject $pushed) use ($task) {
            return $pushed->username === 'acme'
                && $pushed->stages === null
                && $pushed->taskId === $task->id
                && $pushed->queue === 'default';
        });
    }
}
