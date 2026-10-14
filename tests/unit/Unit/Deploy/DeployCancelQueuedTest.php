<?php

namespace Tests\Unit\Deploy;

use App\Http\Middleware\Authenticate;
use App\Jobs\ApplyEnvVars;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * deploy-cancel decided from the deploy log alone. A deploy still waiting in
 * the queue has opened none, so it answered "No running deploy to cancel"
 * while other endpoints refused the project as busy with that very task.
 */
class DeployCancelQueuedTest extends TestCase
{
    use InMemoryDatabase;

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->username = 'qcancel' . bin2hex(random_bytes(3));
        $this->makeUser($this->username, ['template' => 'dind']);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        foreach (['tasks', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_queued_deploy_is_cancelled_by_its_task(): void
    {
        $task = Task::start(RebuildProject::class, 'deploy', $this->username);

        $this->postJson("/api/projects/{$this->username}/deploy-cancel")
            ->assertOk()
            ->assertExactJson(['data' => ['cancelled' => true, 'task_id' => $task->id]]);

        $this->assertSame(Task::STATUS_CANCELLED, $task->refresh()->status);
    }

    public function test_a_queued_task_that_is_not_a_deploy_is_left_alone(): void
    {
        $task = Task::start(ApplyEnvVars::class, 'deploy', $this->username);

        $this->postJson("/api/projects/{$this->username}/deploy-cancel")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'No running or queued deploy to cancel']);

        $this->assertSame(Task::STATUS_QUEUED, $task->refresh()->status);
    }

    public function test_nothing_queued_or_running_is_still_a_conflict(): void
    {
        $this->postJson("/api/projects/{$this->username}/deploy-cancel")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'No running or queued deploy to cancel']);
    }
}
