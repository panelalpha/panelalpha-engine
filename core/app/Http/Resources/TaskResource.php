<?php

namespace App\Http\Resources;

use App\Lib\Task\TaskLogPage;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class TaskResource extends JsonResource
{
    /**
     * @param  Task  $resource
     */
    public function __construct(
        $resource,
        private readonly int $afterId = 0,
        private readonly int $limit = TaskLogPage::DEFAULT_LIMIT,
    ) {
        parent::__construct($resource);
    }

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $task = $this->resource;
        assert($task instanceof Task);

        $page = TaskLogPage::read($task, $this->afterId, limit: $this->limit);

        return self::taskPayload($task) + [
            'logs' => self::logRows($page->rows),
            'next_after_id' => $page->nextAfterId,
            'more' => $page->more,
        ];
    }

    /**
     * @param  Collection<int, TaskLog>  $rows
     * @return list<array<string, mixed>>
     */
    private static function logRows(Collection $rows): array
    {
        $payload = [];
        foreach ($rows as $log) {
            $payload[] = [
                'id' => $log->id,
                'log' => $log->log,
                'created_at' => $log->created_at,
            ];
        }

        return $payload;
    }

    /**
     * The task fields every task representation shares; the token that started
     * it and the worker process are never shown.
     *
     * @return array<string, mixed>
     */
    public static function taskPayload(Task $task): array
    {
        $details = $task->details;
        if (is_array($details)) {
            $details = Arr::except($details, ['api_token_id', Task::WORKER]);
            if ($details === []) {
                $details = null;
            }
        }

        return [
            'id' => $task->id,
            'job_id' => $task->job_id,
            'queue' => $task->queue,
            'job_type' => $task->job_type,
            'status' => $task->status,
            'details' => $details,
            'username' => $task->username,
            'pid' => $task->pid,
            'pid_start_time' => $task->pid_start_time,
            'queued_at' => $task->queued_at,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'failed_at' => $task->failed_at,
            'cancelled_at' => $task->cancelled_at,
        ];
    }
}
