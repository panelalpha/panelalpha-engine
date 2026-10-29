<?php

namespace App\Lib\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Carbon;

/**
 * Batched tee from DeployLogger frames into task_logs.
 *
 * Invokable so it plugs into {@see DeployLogger::streamTo()}.
 * Only `type === line` frames are persisted — stage changes already produce a
 * separate "Starting stage: …" line frame.
 */
final class TaskLogSink
{
    public const BATCH_SIZE = 50;

    /** @var list<array{task_id: int, log: string, created_at: Carbon}> */
    private array $buffer = [];

    public function __construct(private readonly ?Task $task) {}

    /**
     * @param  array<string, mixed>  $frame
     */
    public function __invoke(array $frame): void
    {
        if ($this->task === null) {
            return;
        }
        if (($frame['type'] ?? null) !== 'line') {
            return;
        }

        $payload = json_encode([
            'ts' => (int) ($frame['ts'] ?? time()),
            'stage' => $frame['stage'] ?? null,
            'level' => (string) ($frame['level'] ?? 'info'),
            'msg' => (string) ($frame['msg'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false || $payload === '') {
            return;
        }

        $this->buffer[] = [
            'task_id' => $this->task->id,
            'log' => $payload,
            'created_at' => now(),
        ];

        if (count($this->buffer) >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        TaskLog::insert($this->buffer);
        $this->buffer = [];
    }
}
