<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\Lib\Task\LogStreamLimiter;

/** A task's log read a page at a time, which holds no stream. */
final class ReadTaskLogs extends Hint
{
    public function resolves(): array
    {
        return [LogStreamLimiter::CODE];
    }

    public function rest(): string
    {
        return 'GET /tasks/{id}/logs';
    }

    public function params(): array
    {
        return ['id' => '{task_id}'];
    }
}
