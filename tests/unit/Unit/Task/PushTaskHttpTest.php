<?php

namespace Tests\Unit\Task;

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

class PushTaskHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const TOKEN = 900301;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_push_answers_202_with_a_task_attached_to_the_job(): void
    {
        [$from, $to] = $this->pair();
        $this->actingAsToken();

        $response = $this->postJson("/api/projects/{$from->username}/push", ['target' => $to->username]);

        $response->assertStatus(202);
        $taskId = $response->json('task_id');
        $this->assertIsInt($taskId);

        $task = Task::query()->findOrFail($taskId);
        $this->assertSame(PushState::class, $task->job_type);
        $this->assertSame($to->username, $task->username);
        $this->assertSame(self::TOKEN, $task->api_token_id);
        $this->assertSame('push', $task->details['action']);
        $this->assertSame($from->username, $task->details['source']);
        $this->assertSame($to->username, $task->details['target']);

        $this->assertSame('running', $to->refresh()->asyncStatus()['push'] ?? null);
        Queue::assertPushed(PushState::class, static fn (PushState $job): bool => $job->taskId === $taskId);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $from = $this->makeUser('pushtask-live', ['template' => 'dind']);
        $to = $this->makeUser('pushtask-stg', ['template' => 'dind']);
        $to->staging = $from->id;
        $to->save();

        return [$from->refresh(), $to->refresh()];
    }

    private function actingAsToken(): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => self::TOKEN,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'push-test',
            'token' => hash('sha256', 'push-test'),
            'abilities' => ['api'],
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
    }
}
