<?php

namespace App\Exceptions;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Models\Task;

/**
 * A rebuild or archive deploy asked for while one of the project is queued or
 * running. HTTP answers 409 with the task to follow, or null when the deploy
 * holding the lock has none (the CLI, a push, POST /users, a delete).
 */
class DeployBusyException extends \RuntimeException
{
    public function __construct(string $username, public readonly ?Task $task)
    {
        $holder = $task === null ? DeployLock::heldBy($username) : null;

        parent::__construct(match (true) {
            $task !== null => "A deploy of this project is already queued or running: task {$task->id}. Follow it with "
                . "GET /tasks/{$task->id}; if it is stuck, cancel it with POST /tasks/{$task->id}/cancel.",
            $holder === DeployLock::HOLDER_DELETE => "Project '{$username}' is being deleted.",
            $holder !== null => "Project '{$username}' is busy: a {$holder} is running.",
            default => 'A deploy of this project is already running. Follow it with '
                . "GET /projects/{$username}/deploy-log.",
        }, 409);
    }
}
