<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\PushState;
use App\Jobs\RenameMysqlDatabase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A push and a database rename each refuse while the other is queued or
 * running on either project of the staging pair.
 */
class PushDatabaseRenameOverlapTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_a_rename_on_the_source_of_an_accepted_push_is_refused(): void
    {
        [$live, $stg] = $this->pair();
        $this->postJson("/api/projects/{$live->username}/push", ['target' => $stg->username])->assertStatus(202);

        $this->putJson("/api/projects/{$live->username}/mysql/databases/live_db/rename", ['name' => 'live_db2'])
            ->assertStatus(409)
            ->assertJsonPath('message', "Project '{$live->username}' is busy: a push is running. Wait for it to finish.");

        Queue::assertNotPushed(RenameMysqlDatabase::class);
    }

    public function test_a_push_from_a_project_with_a_queued_rename_is_refused(): void
    {
        [$live, $stg] = $this->pair();
        $this->putJson("/api/projects/{$live->username}/mysql/databases/live_db/rename", ['name' => 'live_db2'])->assertStatus(202);

        $this->postJson("/api/projects/{$live->username}/push", ['target' => $stg->username])->assertStatus(409);

        Queue::assertNotPushed(PushState::class);
        $this->assertNull($stg->refresh()->asyncStatus()['push'] ?? null);
    }

    public function test_a_push_into_a_project_with_a_running_rename_is_refused(): void
    {
        [$live, $stg] = $this->pair();
        Task::start(jobType: RenameMysqlDatabase::class, queue: 'default', username: $stg->username)->markRunning();

        $this->postJson("/api/projects/{$live->username}/push", ['target' => $stg->username])->assertStatus(409);

        Queue::assertNotPushed(PushState::class);
    }

    public function test_the_push_command_refuses_with_a_plain_line(): void
    {
        [$live, $stg] = $this->pair();
        $task = Task::start(jobType: RenameMysqlDatabase::class, queue: 'default', username: $live->username);

        $this->artisan('project:push', ['project' => $live->username, 'target' => $stg->username])
            ->expectsOutput("Project '{$live->username}' is busy: database rename task {$task->id} is queued. Cancel task {$task->id} or wait for it to finish.")
            ->assertExitCode(1);

        $this->assertNull($stg->refresh()->asyncStatus()['push'] ?? null);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $live = $this->makeUser('live', ['mysql_prefix' => 'live_']);
        $stg = $this->makeUser('stg', ['mysql_prefix' => 'stg_']);
        $stg->staging = $live->id;
        $stg->save();
        $live->mysqlDatabases()->create(['database' => 'live_db']);

        return [$live->refresh(), $stg->refresh()];
    }
}
