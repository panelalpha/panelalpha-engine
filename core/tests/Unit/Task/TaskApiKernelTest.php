<?php

namespace Tests\Unit\Task;

use App\Http\Controllers\TaskController;
use App\Http\Middleware\Authenticate;
use App\Models\Task;
use App\Models\TaskLog;

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
}
