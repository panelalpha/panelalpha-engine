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
    /** A deploy is running with no task to follow; its log follows it. */
    public const CODE = 'deploy_busy';

    /** A deploy is queued or running as a task, which can be followed and cancelled. */
    public const TASK = 'deploy_busy_task';

    public readonly string $problemCode;

    public function __construct(string $username, public readonly ?Task $task)
    {
        $holder = $task === null ? DeployLock::heldBy($username) : null;

        $this->problemCode = match (true) {
            $task !== null => self::TASK,
            $holder !== null => ProjectBusyException::CODE,
            default => self::CODE,
        };

        parent::__construct(match (true) {
            $task !== null => "A deploy of this project is already queued or running: task {$task->id}. Follow it, or "
                .'cancel it if it is stuck.',
            $holder === DeployLock::HOLDER_DELETE => "Project '{$username}' is being deleted.",
            $holder !== null => "Project '{$username}' is busy: a {$holder} is running.",
            default => 'A deploy of this project is already running; its deploy log follows it.',
        }, 409);
    }

    /**
     * @return array{code: string, task_id?: int}
     */
    public function problem(): array
    {
        return ['code' => $this->problemCode] + ($this->task === null ? [] : ['task_id' => $this->task->id]);
    }
}
