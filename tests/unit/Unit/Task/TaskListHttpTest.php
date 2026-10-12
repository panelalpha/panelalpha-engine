<?php

namespace Tests\Unit\Task;

use App\Http\Middleware\Authenticate;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Tests\Unit\Task\Support\StubTaskJob;

class TaskListHttpTest extends SqliteTaskTestCase
{
    private const TOKEN_A = 900101;
    private const TOKEN_B = 900202;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_status_active_returns_the_unfinished_tasks_of_one_project(): void
    {
        $running = $this->task('alice', Task::STATUS_RUNNING, ['action' => 'deploy']);
        $queued = $this->task('alice', Task::STATUS_QUEUED, ['action' => 'rebuild']);
        $this->task('alice', Task::STATUS_COMPLETED, ['action' => 'deploy']);
        $this->task('bob', Task::STATUS_RUNNING, ['action' => 'deploy']);

        $response = $this->getJson('/api/tasks?username=alice&status=active');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([$running->id, $queued->id], $this->ids($response->json('data')));
    }

    public function test_status_list_keeps_failed_and_cancelled(): void
    {
        $failed = $this->task('alice', Task::STATUS_FAILED);
        $cancelled = $this->task('alice', Task::STATUS_CANCELLED);
        $this->task('alice', Task::STATUS_COMPLETED);

        $response = $this->getJson('/api/tasks?status=failed,cancelled');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([$failed->id, $cancelled->id], $this->ids($response->json('data')));
    }

    public function test_pagination_returns_one_newest_row_and_the_total(): void
    {
        $older = $this->task('alice', Task::STATUS_COMPLETED);
        $newer = $this->task('alice', Task::STATUS_COMPLETED);

        $response = $this->getJson('/api/tasks?per_page=1');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('meta.per_page', 1);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertNotSame($older->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('links.next'));
    }

    public function test_action_matches_details_action(): void
    {
        $push = $this->task('alice', Task::STATUS_RUNNING, ['action' => 'push']);
        $this->task('alice', Task::STATUS_RUNNING, ['action' => 'deploy']);

        $response = $this->getJson('/api/tasks?action=push');

        $response->assertStatus(200);
        $this->assertSame([$push->id], $this->ids($response->json('data')));
    }

    public function test_since_filters_on_updated_at(): void
    {
        $old = $this->task('alice', Task::STATUS_COMPLETED);
        Task::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);
        $fresh = $this->task('alice', Task::STATUS_COMPLETED);

        $since = urlencode(now()->subDay()->toIso8601String());
        $response = $this->getJson("/api/tasks?since={$since}");

        $response->assertStatus(200);
        $this->assertSame([$fresh->id], $this->ids($response->json('data')));
    }

    public function test_unknown_status_is_a_validation_error(): void
    {
        $this->getJson('/api/tasks?status=bogus')->assertStatus(422);
    }

    public function test_per_page_above_the_cap_is_a_validation_error(): void
    {
        $this->getJson('/api/tasks?per_page=201')->assertStatus(422);
    }

    public function test_scope_mine_hides_another_tokens_tasks_and_shows_engine_work(): void
    {
        $mine = $this->task('alice', Task::STATUS_COMPLETED, tokenId: self::TOKEN_A);
        $this->task('alice', Task::STATUS_COMPLETED, tokenId: self::TOKEN_B);
        $engine = $this->task('alice', Task::STATUS_COMPLETED);

        $this->actingAsToken(self::TOKEN_A);
        $response = $this->getJson('/api/tasks?scope=mine');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([$mine->id, $engine->id], $this->ids($response->json('data')));
    }

    public function test_scope_all_returns_every_tokens_tasks(): void
    {
        $mine = $this->task('alice', Task::STATUS_COMPLETED, tokenId: self::TOKEN_A);
        $theirs = $this->task('bob', Task::STATUS_COMPLETED, tokenId: self::TOKEN_B);

        $this->actingAsToken(self::TOKEN_A);
        $response = $this->getJson('/api/tasks?scope=all');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $this->ids($response->json('data')));
    }

    public function test_scope_all_is_refused_for_a_limited_token(): void
    {
        $this->task('alice', Task::STATUS_COMPLETED, tokenId: self::TOKEN_A);

        $this->actingAsToken(self::TOKEN_A, ['api', 'api:GET /tasks', 'api:GET /projects']);
        $response = $this->getJson('/api/tasks?scope=all');

        $response->assertStatus(403);
    }

    public function test_the_summary_names_the_action_and_token_and_omits_logs(): void
    {
        $this->task('alice', Task::STATUS_RUNNING, ['action' => 'staging'], tokenId: self::TOKEN_A);

        $this->actingAsToken(self::TOKEN_A);
        $response = $this->getJson('/api/tasks?scope=mine&username=alice');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.action', 'staging');
        $response->assertJsonPath('data.0.created_by_token_id', self::TOKEN_A);
        $response->assertJsonMissingPath('data.0.logs');
        $response->assertJsonMissingPath('data.0.next_after_id');
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function task(
        string $username,
        string $status,
        array $details = [],
        ?int $tokenId = null
    ): Task {
        $task = Task::start(
            jobType: StubTaskJob::class,
            queue: 'default',
            username: $username,
            details: $details,
        );
        $task->status = $status;
        $task->api_token_id = $tokenId;
        if ($status === Task::STATUS_RUNNING) {
            $task->started_at = now();
        }
        if ($status === Task::STATUS_COMPLETED) {
            $task->completed_at = now();
        }
        $task->save();

        return $task;
    }

    /**
     * @param array<int, string> $abilities
     */
    private function actingAsToken(int $tokenId, array $abilities = ['api']): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => $tokenId,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'list-test',
            'token' => hash('sha256', 'list-test-' . $tokenId),
            'abilities' => $abilities,
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
    }
}
