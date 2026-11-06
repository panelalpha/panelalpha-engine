<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProjectBusyException;
use App\Lib\Findings\Hints\Hint;

/** The task the project is busy with, cancelled when it is stuck. */
final class CancelTask extends Hint
{
    public function resolves(): array
    {
        return [DeployBusyException::TASK, ProjectBusyException::TASK];
    }

    public function rest(): string
    {
        return 'POST /tasks/{id}/cancel';
    }

    public function params(): array
    {
        return ['id' => '{task_id}'];
    }
}
