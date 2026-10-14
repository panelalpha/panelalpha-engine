<?php

namespace Tests\Unit\Task;

use App\Auth\TokenAbilities;
use App\Http\Middleware\Authenticate;
use App\Jobs\PushState;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A push names two projects: the source in the URL and the target in the body.
 * A token scoped to the source must not be able to push into a project it
 * cannot reach, and a refused push must queue nothing.
 */
class PushTaskScopeTest extends TestCase
{
    use InMemoryDatabase;

    private const TOKEN = 900401;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_a_push_into_an_out_of_scope_target_is_refused_and_queues_nothing(): void
    {
        [$from, $to] = $this->pair();
        $this->actingAsToken([$from->username]);

        $response = $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username]);

        $response->assertStatus(403);
        $this->assertSame(
            "This token is limited to projects {$from->username}; POST /projects/{username}/push is outside it.",
            $response->json('error'),
        );

        $this->assertSame(0, Task::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_push_between_two_scoped_projects_is_queued(): void
    {
        [$from, $to] = $this->pair();
        $this->actingAsToken([$from->username, $to->username]);

        $response = $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username]);

        $response->assertStatus(202);
        $taskId = $response->json('task_id');

        $this->assertSame($to->username, Task::query()->findOrFail($taskId)->username);
        Queue::assertPushed(PushState::class, static fn (PushState $job): bool => $job->taskId === $taskId);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $from = $this->makeUser('scopepush-live', ['template' => 'dind']);
        $to = $this->makeUser('scopepush-stg', ['template' => 'dind']);
        $to->staging = $from->id;
        $to->save();

        return [$from->refresh(), $to->refresh()];
    }

    /**
     * @param array<int, string> $projects
     */
    private function actingAsToken(array $projects): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => self::TOKEN,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'push-scope-test',
            'token' => hash('sha256', 'push-scope-test'),
            'abilities' => TokenAbilities::build(api: true, mcp: false, projects: $projects),
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
        request()->setUserResolver(fn () => $admin);
    }
}
