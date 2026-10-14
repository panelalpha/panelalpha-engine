<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** GET /projects/{username}/deploys and /deploys/{deploy}/log. */
class DeployHistoryHttpTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $usernames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function project(): User
    {
        $username = 'dhh' . bin2hex(random_bytes(4));
        $this->usernames[] = $username;

        return $this->makeUser($username, ['template' => 'dind']);
    }

    private function deploy(User $user, int $lines): string
    {
        $logger = DeployLogger::start($user->username);
        $logger->info('Deploy started (source: rebuild)');
        for ($i = 1; $i < $lines; $i++) {
            $logger->dim("step {$i}");
        }
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        return $logger->getDeployId();
    }

    public function test_the_list_names_each_kept_deploy(): void
    {
        $user = $this->project();
        $id = $this->deploy($user, 3);
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $user->username, details: [
            'action' => 'rebuild', 'trigger' => 'mcp', 'deploy_id' => $id,
        ]);

        $response = $this->getJson("/api/projects/{$user->username}/deploys");

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $id);
        $response->assertJsonPath('data.0.status', DeployLogger::STATUS_SUCCESS);
        $response->assertJsonPath('data.0.kind', 'redeploy');
        $response->assertJsonPath('data.0.source', 'rebuild');
        $response->assertJsonPath('data.0.trigger', 'mcp');
        $response->assertJsonPath('data.0.action', 'rebuild');
        $response->assertJsonPath('data.0.task_id', $task->id);
        $this->assertSame(
            ['id', 'status', 'error', 'started_at', 'finished_at', 'kind', 'source', 'branch', 'commit', 'trigger', 'action', 'task_id'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_a_project_that_never_deployed_lists_nothing(): void
    {
        $user = $this->project();

        $this->getJson("/api/projects/{$user->username}/deploys")->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_an_older_deploys_log_is_read_by_its_id_in_pages(): void
    {
        $user = $this->project();
        $first = $this->deploy($user, 4);
        $this->deploy($user, 2);

        $page = $this->getJson("/api/projects/{$user->username}/deploys/{$first}/log?offset=1");

        $page->assertOk();
        $page->assertJsonPath('data.id', $first);
        $page->assertJsonPath('data.status', DeployLogger::STATUS_SUCCESS);
        $page->assertJsonPath('data.lines.0.msg', 'step 1');
        $page->assertJsonPath('data.more', false);
        $this->assertSame(5, $page->json('data.next_offset'), 'four lines and the finish line');
    }

    public function test_an_unknown_project_or_deploy_is_404(): void
    {
        $user = $this->project();
        $this->deploy($user, 1);

        $this->getJson('/api/projects/nobody/deploys')->assertNotFound()->assertExactJson(['message' => 'Project not found']);
        $this->getJson("/api/projects/{$user->username}/deploys/20260101-000000-ffffff/log")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Deploy not found']);
        $this->getJson("/api/projects/{$user->username}/deploys/..%2Flatest/log")->assertNotFound();
    }

    public function test_another_projects_deploy_is_not_found_here(): void
    {
        $owner = $this->project();
        $other = $this->project();
        $id = $this->deploy($owner, 1);

        $this->getJson("/api/projects/{$other->username}/deploys/{$id}/log")->assertNotFound();
    }
}
