<?php

namespace Tests\Unit\Deploy;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\DeployCancelRequest;
use App\Jobs\RebuildProject;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * deploy-cancel shared deploy-log's guard, so a classic project was refused
 * with a 403 about deploy logs. It answers about cancelling, as the other
 * container-only operations do, with a 422 its OpenAPI annotation documents.
 */
class DeployCancelClassicProjectTest extends TestCase
{
    use InMemoryDatabase;

    private const MESSAGE = 'Deploy cancel works only on container projects (template dind).';

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->username = 'ccancel' . bin2hex(random_bytes(3));
        $this->makeUser($this->username, ['template' => 'php']);
    }

    protected function tearDown(): void
    {
        foreach (['tasks', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_cancel_on_a_classic_project_names_the_cancel(): void
    {
        $this->postJson("/api/projects/{$this->username}/deploy-cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('errors.project.0', self::MESSAGE)
            ->assertJsonPath('problems.0.field', 'project')
            ->assertJsonPath('problems.0.code', DeployCancelRequest::CONTAINER_ONLY);
    }

    public function test_cancel_on_a_classic_project_leaves_its_tasks_alone(): void
    {
        $task = Task::start(RebuildProject::class, 'deploy', $this->username);

        $this->postJson("/api/projects/{$this->username}/deploy-cancel")->assertStatus(422);

        $this->assertSame(Task::STATUS_QUEUED, $task->refresh()->status);
    }

    public function test_the_deploy_log_of_a_classic_project_is_still_refused_as_before(): void
    {
        $this->getJson("/api/projects/{$this->username}/deploy-log")
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Deploy logs work only on container projects (template dind).']);
    }

    public function test_cancel_on_a_project_that_does_not_exist_is_not_found(): void
    {
        $this->postJson('/api/projects/nosuchproject/deploy-cancel')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Project not found']);
    }
}
