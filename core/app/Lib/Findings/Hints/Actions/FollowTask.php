<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Findings\Hints\Hint;

/** The task the project is busy with, followed to its end. */
final class FollowTask extends Hint
{
    public function resolves(): array
    {
        return [DeployBusyException::TASK, ProjectBusyException::TASK];
    }

    public function rest(): string
    {
        return 'GET /tasks/{id}';
    }

    public function params(): array
    {
        return ['id' => '{task_id}'];
    }
}
