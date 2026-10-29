<?php

namespace App\Http\Resources;

use App\Lib\Task\TaskLogPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TaskLogCollection extends ResourceCollection
{
    public $collects = TaskLogResource::class;

    public function __construct(private readonly TaskLogPage $page, private readonly string $taskStatus)
    {
        parent::__construct($page->rows);
    }

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        return [
            'meta' => [
                'next_after_id' => $this->page->nextAfterId,
                'more' => $this->page->more,
                'task_status' => $this->taskStatus,
            ],
        ];
    }
}
