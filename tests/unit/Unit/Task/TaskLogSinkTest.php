<?php

namespace Tests\Unit\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\TaskLogSink;
use App\Models\Task;
use App\Models\TaskLog;

class TaskLogSinkTest extends SqliteTaskTestCase
{
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function username(): string
    {
        $username = 'sink-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    public function test_line_frames_are_persisted_as_json_and_stage_frames_are_ignored(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');
        $sink = new TaskLogSink($task);
        DeployLogger::streamTo($sink);

        try {
            $logger = DeployLogger::start($this->username());
            $logger->stage(DeployLogger::STAGE_CLONING);
            $logger->info('cloning repo');
            $logger->dim('npm notice');
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        } finally {
            $sink->flush();
            DeployLogger::stopStreaming();
        }

        $rows = TaskLog::query()->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());

        $payloads = $rows->map(static fn (TaskLog $row) => json_decode($row->log, true))->all();
        foreach ($payloads as $payload) {
            $this->assertIsArray($payload);
            $this->assertArrayHasKey('msg', $payload);
            $this->assertArrayHasKey('level', $payload);
            // No bare stage frames — only writeLine payloads.
            $this->assertArrayNotHasKey('type', $payload);
        }

        $messages = array_column($payloads, 'msg');
        $this->assertContains('Starting stage: cloning', $messages);
        $this->assertContains('cloning repo', $messages);
        $this->assertContains('Deploy finished successfully', $messages);
    }

    public function test_null_task_is_a_noop(): void
    {
        $sink = new TaskLogSink(null);
        $sink([
            'type' => 'line',
            'ts' => time(),
            'stage' => null,
            'level' => 'info',
            'msg' => 'ignored',
        ]);
        $sink->flush();

        $this->assertSame(0, TaskLog::count());
    }

    public function test_flush_is_required_for_partial_batch(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');
        $sink = new TaskLogSink($task);

        $sink([
            'type' => 'line',
            'ts' => time(),
            'stage' => 'running',
            'level' => 'info',
            'msg' => 'one',
        ]);
        $this->assertSame(0, TaskLog::count());

        $sink->flush();
        $this->assertSame(1, TaskLog::count());
        $decoded = json_decode(TaskLog::first()->log, true);
        $this->assertSame('one', $decoded['msg']);
        $this->assertSame('info', $decoded['level']);
        $this->assertSame('running', $decoded['stage']);
    }

    public function test_batch_auto_flushes_at_batch_size(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');
        $sink = new TaskLogSink($task);

        for ($i = 0; $i < TaskLogSink::BATCH_SIZE; $i++) {
            $sink([
                'type' => 'line',
                'ts' => time(),
                'stage' => null,
                'level' => 'dim',
                'msg' => 'line-' . $i,
            ]);
        }

        $this->assertSame(TaskLogSink::BATCH_SIZE, TaskLog::count());
    }
}
