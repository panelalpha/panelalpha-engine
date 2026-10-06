<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class TaskResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $task = $this->resource;
        assert($task instanceof Task);

        $afterId = max(0, (int) $request->query('after_id', 0));
        $logs = $task->logsAfter($afterId, 2000);
        $nextAfterId = $logs->isEmpty() ? $afterId : (int) $logs->last()->id;

        $logPayload = [];
        foreach ($logs as $log) {
            $logPayload[] = [
                'id' => $log->id,
                'log' => $log->log,
                'created_at' => $log->created_at,
            ];
        }

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
            'logs' => $logPayload,
            'next_after_id' => $nextAfterId,
        ];
    }
}
