<?php

namespace App\Lib\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Retire `running` tasks whose worker died, since nothing else writes their terminal status and task:prune skips
 * them. The queue is the evidence ({@see queueCheck()}), not the deploy log rollback deletes; it fails closed.
 */
final class TaskReconciler
{
    public const ORPHAN_MESSAGE = DeployLogger::INTERRUPTED_MESSAGE;

    public const RETIRED_ORPHANED = 'orphaned';

    public const RETIRED_CANCELLED = 'cancelled';

    /**
     * `$olderThanSeconds` spares a task between `markRunning()` and its job being pushed. Retired ids come back
     * under `cancelled` when the task's deploy log says it was cancelled, and under `orphaned` otherwise.
     *
     * @param  null|callable(Task): ?bool  $isPending  overrides the queue check
     * @return array{orphaned: list<int>, cancelled: list<int>}
     */
    public static function reconcile(
        bool $dryRun = false,
        ?int $olderThanSeconds = null,
        ?callable $isPending = null,
    ): array {
        $query = Task::query()->where('status', Task::STATUS_RUNNING);

        if ($olderThanSeconds !== null && $olderThanSeconds > 0) {
            $query->where('started_at', '<', now()->subSeconds($olderThanSeconds));
        }

        $retired = [self::RETIRED_ORPHANED => [], self::RETIRED_CANCELLED => []];
        foreach ($query->get() as $task) {
            $kind = self::retire($task, $dryRun, $isPending);
            if ($kind !== null) {
                $retired[$kind][] = (int) $task->id;
            }
        }

        if (! $dryRun) {
            self::dropRowsOfCancelledDeadWorkers();
        }

        return $retired;
    }

    /**
     * How the task is to be retired, or null to leave it; outside a dry run it is, along with
     * a dead worker's queue row.
     *
     * @param  null|callable(Task): ?bool  $isPending
     */
    private static function retire(Task $task, bool $dryRun, ?callable $isPending): ?string
    {
        $latest = self::deployLogOf($task);
        $decision = self::decide($task, $latest, $isPending);

        if ($decision === null) {
            return null;
        }
        $kind = self::logWasCancelled($latest) ? self::RETIRED_CANCELLED : self::RETIRED_ORPHANED;
        if ($dryRun) {
            return $kind;
        }

        self::apply($task, $decision, $latest);
        if (self::workerGone($task)) {
            TaskJobQueue::dropReservedJob($task);
        }

        return $kind;
    }

    /**
     * A task cancelled after its worker died is terminal, so reconcile() skips it, but its reserved row is as
     * stale. Rows older than a day have been failed by a worker at retry_after already.
     */
    private static function dropRowsOfCancelledDeadWorkers(): void
    {
        $cancelled = Task::query()
            ->where('status', Task::STATUS_CANCELLED)
            ->whereNotNull('job_id')
            ->where('cancelled_at', '>=', now()->subDay())
            ->get();
        foreach ($cancelled as $task) {
            if (self::workerGone($task)) {
                TaskJobQueue::dropReservedJob($task);
            }
        }
    }

    /**
     * The status this task should have, or null to leave it running. A terminal deploy log is adopted whatever the
     * queue says; otherwise a dead worker or a job gone from the queue cancels it, and an unknown queue leaves it.
     *
     * @param  array<string, mixed>|null  $latest  the deploy log, when one is readable
     * @param  null|callable(Task): ?bool  $isPending
     */
    public static function decide(Task $task, ?array $latest, ?callable $isPending = null): ?string
    {
        if ($latest !== null && ($latest['status'] ?? null) !== DeployLogger::STATUS_RUNNING) {
            return match ($latest['status']) {
                DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL, DeployLogger::STATUS_WAITING => Task::STATUS_COMPLETED,
                DeployLogger::STATUS_CANCELLED => Task::STATUS_CANCELLED,
                default => Task::STATUS_FAILED,
            };
        }

        if (self::workerGone($task)) {
            return self::childHoldsTheDeployLock($task, $latest) ? null : Task::STATUS_CANCELLED;
        }

        $pending = ($isPending ?? self::queueCheck())($task);

        return $pending === false ? Task::STATUS_CANCELLED : null;
    }

    /**
     * A child the dead worker started may still hold its deploy's lock; the log is closed
     * with a verdict once that ends.
     *
     * @param  array<string, mixed>|null  $latest
     */
    private static function childHoldsTheDeployLock(Task $task, ?array $latest): bool
    {
        return $latest !== null && DeployLogger::isLockedFor((string) $task->username);
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function apply(Task $task, string $status, ?array $latest): void
    {
        if ($status === Task::STATUS_COMPLETED) {
            $task->markCompleted();
            Log::warning('Task '.$task->id.' for '.$task->username
                .' was reconciled to completed from its deploy log.');

            return;
        }

        if ($status === Task::STATUS_FAILED) {
            self::failWithTheLogsReason($task, $latest);

            return;
        }

        if (self::logWasCancelled($latest)) {
            self::cancelAsTheLogWas($task);

            return;
        }

        self::retireWithoutVerdict($task);
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function logWasCancelled(?array $latest): bool
    {
        return ($latest['status'] ?? null) === DeployLogger::STATUS_CANCELLED;
    }

    private static function cancelAsTheLogWas(Task $task): void
    {
        $task->markCancelled();
        Log::warning('Task '.$task->id.' for '.$task->username
            .' was reconciled to cancelled from its deploy log.');
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private static function failWithTheLogsReason(Task $task, ?array $latest): void
    {
        $reason = $latest['error'] ?? null;
        $task->markFailed(is_string($reason) && $reason !== '' ? $reason : self::ORPHAN_MESSAGE);
    }

    /**
     * Interrupted with no verdict anywhere, so none is invented: `cancelled` is what this
     * engine already means by "stopped without a verdict".
     */
    private static function retireWithoutVerdict(Task $task): void
    {
        $task->details = array_merge($task->details ?? [], ['reconciled' => self::ORPHAN_MESSAGE]);
        $task->markCancelled();
        Log::warning('Task '.$task->id.' for '.$task->username
            .' was retired: its job is no longer in the queue and nothing finished it.');
    }

    /**
     * Whether this task's worker died, which retires it though its job stays reserved for a day; false when none
     * was recorded. Workers and this sweep share the core container, so the pid is read from the same process table.
     */
    public static function workerGone(Task $task): bool
    {
        $worker = $task->details[Task::WORKER] ?? null;
        if (! is_array($worker) || ! is_int($worker['pid'] ?? null) || ! is_string($worker['start'] ?? null)) {
            return false;
        }

        return ! ProcessIdentity::isStillRunning($worker['pid'], $worker['start']);
    }

    /**
     * Whether the task's job is still queued or reserved by a worker ({@see TaskJobQueue}); null when that
     * cannot be answered, which the caller treats as "leave it alone".
     *
     * @param  null|callable(string): iterable<mixed>  $payloads
     *                                                            overrides the table read, so the check itself is testable
     * @return callable(Task): ?bool
     */
    public static function queueCheck(?callable $payloads = null): callable
    {
        return static fn (Task $task): ?bool => TaskJobQueue::isQueued($task, $payloads);
    }

    /**
     * Whether any serialised queue member is the given job.
     *
     * @param  array<int, mixed>  $members  raw ZSET or list members
     */
    public static function membersCarryJob(array $members, string $jobId): bool
    {
        return TaskJobQueue::membersCarryJob($members, $jobId);
    }

    /**
     * The deploy log this task's username last wrote, when this task wrote it: an older deploy's verdict says
     * nothing of its work. A recorded deploy id is matched as is, since a resumed log started before the task.
     *
     * @return array<string, mixed>|null
     */
    public static function deployLogOf(Task $task): ?array
    {
        if (! $task->isDeploy()) {
            return null;
        }

        $username = $task->username;
        if (! is_string($username) || $username === '') {
            return null;
        }

        $latest = self::readLatestLog($username);
        if (! is_array($latest)) {
            return null;
        }

        $deployId = $task->details['deploy_id'] ?? null;
        if (is_string($deployId) && $deployId !== '') {
            return ($latest['id'] ?? null) === $deployId ? $latest : null;
        }

        return self::startedBeforeTheTask($task, $latest) ? null : $latest;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readLatestLog(string $username): ?array
    {
        try {
            return DeployLogger::readLatestFor($username);
        } catch (\Throwable $e) {
            Log::warning('Could not read the deploy log for '.$username.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * A log started well before the task did belongs to an earlier deploy. The minute
     * of slack absorbs clock and ordering jitter.
     *
     * @param  array<string, mixed>  $latest
     */
    private static function startedBeforeTheTask(Task $task, array $latest): bool
    {
        $startedFrom = $latest['started_at'] ?? null;

        return $task->started_at !== null && is_numeric($startedFrom)
            && (int) $startedFrom < $task->started_at->getTimestamp() - 60;
    }
}
