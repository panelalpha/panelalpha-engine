<?php

namespace Tests\Unit\Task;

use App\Http\Controllers\TaskController;
use App\Http\Middleware\Authenticate;
use App\Lib\Task\TaskLogPage;
use App\Models\Task;
use App\Models\TaskLog;
use PHPUnit\Framework\Attributes\DataProvider;

class TaskApiKernelTest extends SqliteTaskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_get_existing_task_with_no_logs_returns_after_id_as_cursor(): void
    {
        $task = Task::start(
            jobType: 'App\\Jobs\\RebuildJob',
            queue: 'default',
            username: 'acme',
            details: ['reason' => 'manual'],
        );

        $response = $this->getJson('/api/tasks/' . $task->id . '?after_id=0');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $task->id);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.queue', 'default');
        $response->assertJsonPath('data.job_type', 'App\\Jobs\\RebuildJob');
        $response->assertJsonPath('data.username', 'acme');
        $response->assertJsonPath('data.details.reason', 'manual');
        $response->assertJsonPath('data.job_id', null);
        $response->assertJsonPath('data.pid', null);
        $response->assertJsonPath('data.logs', []);
        $response->assertJsonPath('data.next_after_id', 0);
        $this->assertNotNull($response->json('data.queued_at'));
    }

    public function test_get_keeps_the_action_under_details_not_at_the_top_level(): void
    {
        $task = Task::start(
            jobType: 'App\\Jobs\\PushState',
            queue: 'default',
            username: 'acme',
            details: ['action' => 'push', 'source' => 'live', 'target' => 'acme'],
        );

        $response = $this->getJson('/api/tasks/' . $task->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.details.action', 'push');
        $this->assertArrayNotHasKey('action', $response->json('data'));
    }

    public function test_get_returns_only_logs_after_cursor_and_caps_page(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $first = TaskLog::create(['task_id' => $task->id, 'log' => 'one']);
        TaskLog::create(['task_id' => $task->id, 'log' => 'two']);
        TaskLog::create(['task_id' => $task->id, 'log' => 'three']);

        $response = $this->getJson('/api/tasks/' . $task->id . '?after_id=' . $first->id);

        $response->assertStatus(200);
        $logs = $response->json('data.logs');
        $this->assertCount(2, $logs);
        $this->assertSame('two', $logs[0]['log']);
        $this->assertSame('three', $logs[1]['log']);
        $this->assertSame($logs[1]['id'], $response->json('data.next_after_id'));
    }

    public function test_get_pages_by_limit_and_says_whether_more_follow(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $this->lines($task, 3);

        $first = $this->getJson('/api/tasks/' . $task->id . '?limit=2');

        $first->assertStatus(200);
        $this->assertSame(['line-0', 'line-1'], array_column($first->json('data.logs'), 'log'));
        $first->assertJsonPath('data.more', true);

        $last = $this->getJson('/api/tasks/' . $task->id . '?limit=2&after_id=' . $first->json('data.next_after_id'));

        $this->assertSame(['line-2'], array_column($last->json('data.logs'), 'log'));
        $last->assertJsonPath('data.more', false);
        $last->assertJsonPath('data.next_after_id', $last->json('data.logs.0.id'));
    }

    public function test_get_without_limit_gives_the_default_page(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $this->lines($task, TaskLogPage::DEFAULT_LIMIT + 1);

        $response = $this->getJson('/api/tasks/' . $task->id);

        $this->assertCount(TaskLogPage::DEFAULT_LIMIT, $response->json('data.logs'));
        $response->assertJsonPath('data.more', true);
    }

    public function test_get_logs_pages_by_limit_and_says_whether_more_follow(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $this->lines($task, 3);

        $first = $this->getJson('/api/tasks/' . $task->id . '/logs?limit=2');

        $this->assertSame(['line-0', 'line-1'], array_column($first->json('data'), 'log'));
        $first->assertJsonPath('meta.more', true);
        $first->assertJsonPath('meta.next_after_id', $first->json('data.1.id'));

        $last = $this->getJson('/api/tasks/' . $task->id . '/logs?limit=2&after_id=' . $first->json('meta.next_after_id'));

        $this->assertSame(['line-2'], array_column($last->json('data'), 'log'));
        $last->assertJsonPath('meta.more', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badPages(): array
    {
        return [
            'limit 0' => ['limit=0'],
            'limit over the maximum' => ['limit=' . (TaskLogPage::MAX_LIMIT + 1)],
            'limit not a number' => ['limit=all'],
            'negative after_id' => ['after_id=-1'],
        ];
    }

    #[DataProvider('badPages')]
    public function test_a_bad_page_is_422_on_both_page_endpoints(string $query): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $field = explode('=', $query)[0];

        $this->getJson('/api/tasks/' . $task->id . '?' . $query)->assertStatus(422)->assertJsonValidationErrors($field);
        $this->getJson('/api/tasks/' . $task->id . '/logs?' . $query)->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public function test_get_unknown_id_returns_404(): void
    {
        $response = $this->getJson('/api/tasks/999999999');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Not found');
    }

    public function test_post_cancel_on_queued_then_get_shows_cancelled(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $cancel = $this->postJson('/api/tasks/' . $task->id . '/cancel');
        $cancel->assertStatus(200);
        $cancel->assertJsonPath('data.cancelled', true);

        $show = $this->getJson('/api/tasks/' . $task->id);
        $show->assertStatus(200);
        $show->assertJsonPath('data.status', 'cancelled');
        $this->assertNotNull($show->json('data.cancelled_at'));
    }

    public function test_post_cancel_on_completed_returns_409(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCompleted();

        $response = $this->postJson('/api/tasks/' . $task->id . '/cancel');

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Task cannot be cancelled');
    }

    public function test_post_cancel_unknown_id_returns_404(): void
    {
        $response = $this->postJson('/api/tasks/999999999/cancel');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Not found');
    }

    public function test_get_logs_filters_by_since_and_returns_meta(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markRunning('j1');

        $old = TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => 1, 'stage' => 'preparing', 'level' => 'info', 'msg' => 'old']),
        ]);
        $old->created_at = now()->subMinutes(10);
        $old->save();

        $fresh = TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => 2, 'stage' => 'cloning', 'level' => 'ok', 'msg' => 'fresh']),
        ]);

        $response = $this->getJson('/api/tasks/' . $task->id . '/logs?since=' . $old->created_at->timestamp);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.task_status', 'running');
        $response->assertJsonPath('meta.next_after_id', $fresh->id);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('fresh', $data[0]['log']);
        $this->assertSame('ok', $data[0]['level']);
        $this->assertSame('cloning', $data[0]['stage']);
    }

    public function test_get_logs_plain_string_has_null_level_and_stage(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        TaskLog::create(['task_id' => $task->id, 'log' => 'clone started']);

        $response = $this->getJson('/api/tasks/' . $task->id . '/logs');

        $response->assertStatus(200);
        $this->assertSame('clone started', $response->json('data.0.log'));
        $this->assertNull($response->json('data.0.level'));
        $this->assertNull($response->json('data.0.stage'));
    }

    public function test_get_logs_unknown_id_returns_404(): void
    {
        $response = $this->getJson('/api/tasks/999999999/logs');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Not found');
    }

    public function test_an_unparseable_since_is_422_on_both_log_endpoints(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');

        $this->getJson('/api/tasks/' . $task->id . '/logs?since=abc')
            ->assertStatus(422)
            ->assertJsonPath('message', Task::SINCE_EXPECTED);

        // The stream validates before its 200 and its callback, so it is a plain
        // 422 too, not an empty or cut-off stream.
        $this->get('/api/tasks/' . $task->id . '/logs/stream?since=abc')
            ->assertStatus(422)
            ->assertJsonPath('message', Task::SINCE_EXPECTED);
    }

    public function test_a_parseable_since_still_pages_and_streams(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $task->markCompleted();
        TaskLog::create(['task_id' => $task->id, 'log' => 'line']);

        $this->getJson('/api/tasks/' . $task->id . '/logs?since=2026-01-01T00:00:00Z')->assertStatus(200);
        $this->get('/api/tasks/' . $task->id . '/logs/stream?since=2026-01-01T00:00:00Z')->assertStatus(200);
    }

    public function test_get_logs_stream_emits_ndjson_and_finish_for_terminal_task(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => 1, 'stage' => null, 'level' => 'info', 'msg' => 'one']),
        ]);
        TaskLog::create([
            'task_id' => $task->id,
            'log' => json_encode(['ts' => 2, 'stage' => null, 'level' => 'info', 'msg' => 'two']),
        ]);
        $task->markCompleted();

        $response = $this->get('/api/tasks/' . $task->id . '/logs/stream');

        $response->assertStatus(200);
        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Content-Length'));

        $body = $response->streamedContent();
        $frames = array_values(array_filter(array_map(
            static fn (string $line) => json_decode($line, true),
            explode("\n", trim($body)),
        )));
        $this->assertCount(3, $frames);
        $this->assertSame('one', $frames[0]['log']);
        $this->assertSame('two', $frames[1]['log']);
        $this->assertSame('finish', $frames[2]['type']);
        $this->assertSame('completed', $frames[2]['status']);
    }

    public function test_get_logs_stream_sends_heartbeats_while_the_task_is_quiet(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\RebuildJob', queue: 'default');
        $started = microtime(true);
        // The task logs one line and finishes once the stream has been quiet past the heartbeat.
        Task::retrieved(static function (Task $seen) use ($started): void {
            if (microtime(true) - $started > TaskController::STREAM_HEARTBEAT_SECONDS + 0.5
                && TaskLog::query()->where('task_id', $seen->id)->doesntExist()) {
                TaskLog::create([
                    'task_id' => $seen->id,
                    'log' => json_encode(['ts' => 1, 'stage' => null, 'level' => 'info', 'msg' => 'late']),
                ]);
                Task::query()->whereKey($seen->id)->update(['status' => Task::STATUS_COMPLETED]);
            }
        });

        $body = $this->get('/api/tasks/' . $task->id . '/logs/stream')->streamedContent();

        $frames = array_map(
            static fn (string $line) => json_decode($line, true),
            explode("\n", trim($body)),
        );
        $this->assertSame(['type' => 'heartbeat'], $frames[0]);
        $this->assertSame('late', $frames[1]['log']);
        $this->assertSame(['type' => 'finish', 'status' => 'completed'], $frames[2]);
        $this->assertCount(3, $frames);
    }

    private function lines(Task $task, int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['task_id' => $task->id, 'log' => 'line-' . $i, 'created_at' => now()];
        }
        TaskLog::insert($rows);
    }
}
