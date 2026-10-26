<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskSummaryResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $task = $this->resource;
        assert($task instanceof Task);

        $details = is_array($task->details) ? $task->details : [];

        return TaskResource::taskPayload($task) + [
            'action' => $details['action'] ?? null,
            'created_by_token_id' => $task->api_token_id,
        ];
    }
}
