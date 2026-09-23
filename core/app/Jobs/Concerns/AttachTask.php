<?php

namespace App\Jobs\Concerns;

use App\Exceptions\TaskCancelledException;
use App\Jobs\Middleware\RecordCoverage;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Lib\Task\TaskPruner;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

trait AttachTask
{
    public ?int $taskId = null;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RecordCoverage];
    }

    public function attachTask(Task $task): static
    {
        if ($this->taskId !== null) {
            throw new LogicException('Task already attached');
        }

        $this->taskId = $task->id;

        if (is_string($this->queue) && $this->queue !== '') {
            $task->queue = $this->queue;
            $task->save();
        }

        return $this;
    }

    public function task(): ?Task
    {
        if ($this->taskId === null) {
            return null;
        }

        return Task::find($this->taskId);
    }

    public function markRunning(): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        // The payload uuid, not getJobId(): a database job's id is its row id.
        $jobId = null;
        if (isset($this->job) && is_object($this->job) && method_exists($this->job, 'uuid')) {
            $jobId = $this->job->uuid();
        }
        $task->markRunning(is_string($jobId) ? $jobId : null);
    }

    public function markCompleted(): void
    {
        $this->task()?->markCompleted();
    }

    public function markFailed(Throwable $e): void
    {
        $this->task()?->markFailed($e->getMessage());
    }

    public function markCancelled(): void
    {
        $this->task()?->markCancelled();
    }

    public function setTaskPid(?int $pid): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        $startTime = $pid === null ? null : ProcessIdentity::startTime($pid);
        $task->setPid($pid, $startTime);
    }

    public function logTask(string $log): void
    {
        $task = $this->task();
        if ($task === null || $log === '') {
            return;
        }

        TaskLog::create([
            'task_id' => $task->id,
            'log' => $log,
        ]);
    }

    public function isCancelled(): bool
    {
        $task = $this->task();

        return $task !== null && $task->status === Task::STATUS_CANCELLED;
    }

    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new TaskCancelledException();
        }
    }

    public function runTask(callable $work): mixed
    {
        $task = $this->task();
        if ($task === null) {
            return $work();
        }

        $this->markRunning();
        if ($this->isCancelled()) {
            return null;
        }

        try {
            $result = $work();
            $this->markCompleted();

            return $result;
        } catch (TaskCancelledException $e) {
            $this->markCancelled();

            return null;
        } catch (Throwable $e) {
            $this->markFailed($e);
            throw $e;
        } finally {
            $this->trimAttachedTaskLogs();
        }
    }

    private function trimAttachedTaskLogs(): void
    {
        try {
            $task = $this->task();
            if ($task === null) {
                return;
            }
            $task->refresh();
            TaskPruner::trimTask($task);
        } catch (Throwable $e) {
            Log::warning('Task log trim failed: ' . $e->getMessage());
        }
    }
}
