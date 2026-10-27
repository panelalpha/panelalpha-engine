<?php

namespace App\Lib\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retire `running` tasks whose worker is gone.
 *
 * A task row is the only handle a client has on a job: `GET /tasks/{id}`
 * answers from it forever. When the process running the work dies -- a host
 * reboot, an OOM kill, a restarted queue worker -- nothing writes a
 * terminal status, because the writer is the thing that died. `task:prune`
 * cannot help: it skips non-terminal rows by design.
 *
 * **The queue is the evidence, not the deploy log.** A job keeps its row in
 * the `jobs` table while it is pending or reserved by a worker, and loses it
 * when it finishes, so "is this job still queued?" is answered exactly. The
 * job uuid is a field inside the payload, so it is matched by decoding
 * ({@see queueCheck()}).
 *
 * A missing deploy log is no evidence: the log is deleted with the account on
 * rollback, the last thing every failed deploy does, so it is absent both when
 * a job died and when it is finishing normally. Once the queue says the job is
 * gone, a log's terminal status is still the deploy's own verdict, and adopted.
 *
 * Fails *closed* at every step. An unreadable queue, a non-deploy job with no
 * recorded pid, a job still reserved -- all leave the row alone. The one
 * exception is a reserved job whose recorded worker process has died: the
 * row stays reserved until retry_after, a day, though nothing runs it.
 */
final class TaskReconciler
{
    public const ORPHAN_MESSAGE = DeployLogger::INTERRUPTED_MESSAGE;

    public const RETIRED_ORPHANED = 'orphaned';

    public const RETIRED_CANCELLED = 'cancelled';

    /**
     * `$olderThanSeconds` spares a task that started moments ago: it may be between
     * `markRunning()` and its job being pushed. The ids it retired come back
     * under `cancelled` when the task's deploy log says the deploy was cancelled,
     * and under `orphaned` otherwise.
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
            self::dropReservedJob($task);
        }

        return $kind;
    }

    /**
     * A task cancelled after its worker died is already terminal, so reconcile()
     * never sees it, but its reserved row is just as stale. Rows older than a day
     * have been failed by a worker at retry_after already.
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
                self::dropReservedJob($task);
            }
        }
    }

    /**
     * The dead worker's queue row, still reserved: deleted, then recorded as failed
     * (that record is only for the record), so a worker does not run the job's failed()
     * a day later, at retry_after. That would act on whatever has the job's names by then --
     * CreateStaging's destroys the staging project, which may have been
     * created again since this task was retired.
     */
    private static function dropReservedJob(Task $task): void
    {
        $jobId = $task->job_id;
        if (! is_string($jobId) || $jobId === '') {
            return;
        }
        $table = self::jobsTable();
        $queue = self::queueOf($task);

        try {
            foreach (DB::table($table)->where('queue', $queue)->get(['id', 'payload']) as $row) {
                if (self::memberUuid((string) $row->payload) !== $jobId) {
                    continue;
                }
                DB::table($table)->where('id', $row->id)->delete();
                app('queue.failer')->log('database', $queue, (string) $row->payload, new \RuntimeException(self::ORPHAN_MESSAGE));
            }
        } catch (\Throwable $e) {
            Log::warning('Could not drop the queue row of retired task '.$task->id.': '.$e->getMessage());
        }
    }

    /**
     * The status this task should have, or null to leave it running. A deploy log's
     * terminal status is the work's own verdict and is adopted whatever the queue says;
     * a cancelled log cancels the task, as the job would once it stopped.
     * Otherwise a dead worker's task is cancelled, and so is one whose job left the queue
     * with nothing finishing it; a pending job, or a queue that cannot tell (null), is left alone.
     *
     * @param  array<string, mixed>|null  $latest  the deploy log, when one is readable
     * @param  null|callable(Task): ?bool  $isPending
     */
    public static function decide(Task $task, ?array $latest, ?callable $isPending = null): ?string
    {
        if ($latest !== null && ($latest['status'] ?? null) !== DeployLogger::STATUS_RUNNING) {
            return match ($latest['status']) {
                DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL => Task::STATUS_COMPLETED,
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
     * Whether the worker process that took this task has died. False when no
     * worker was recorded (a task from before it was), since that is not
     * evidence of anything. Workers and this sweep share the core container,
     * so the pid and its start time are read from the same process table.
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
     * Whether the task's job is still queued or reserved by a worker.
     *
     * Null means the question could not be answered, which the caller treats
     * as "leave it alone".
     *
     * A row in the `jobs` table is a job that is pending or reserved; Laravel
     * deletes it when the job finishes or fails. The uuid is a field inside
     * the payload, so it is matched by decoding. {@see membersCarryJob()}
     *
     * @param  null|callable(string): iterable<mixed>  $payloads
     *                                                            overrides the table read, so the check itself is testable
     * @return callable(Task): ?bool
     */
    public static function queueCheck(?callable $payloads = null): callable
    {
        return static fn (Task $task): ?bool => self::isQueued($task, $payloads);
    }

    /**
     * Null when the task never recorded a job uuid (a job that died before a worker took it)
     * or the queue cannot be read. The queue is read whole, which its depth bounds.
     *
     * @param  null|callable(string): iterable<mixed>  $payloads
     */
    private static function isQueued(Task $task, ?callable $payloads): ?bool
    {
        $jobId = $task->job_id;
        if (! is_string($jobId) || $jobId === '') {
            return null;
        }

        $queue = self::queueOf($task);

        try {
            $payloads ??= static fn (string $queue): iterable => DB::table(self::jobsTable())
                ->where('queue', $queue)
                ->pluck('payload');

            $members = [];
            foreach ($payloads($queue) as $payload) {
                $members[] = $payload;
            }

            return self::membersCarryJob($members, $jobId);
        } catch (\Throwable $e) {
            Log::warning('Could not read the queue to reconcile task '.$task->id.': '.$e->getMessage());

            return null;
        }
    }

    private static function jobsTable(): string
    {
        return config('queue.connections.database.table') ?? 'jobs';
    }

    private static function queueOf(Task $task): string
    {
        return is_string($task->queue) && $task->queue !== '' ? $task->queue : 'default';
    }

    /**
     * Whether any serialised queue member is the given job.
     *
     * @param  array<int, mixed>  $members  raw ZSET or list members
     */
    public static function membersCarryJob(array $members, string $jobId): bool
    {
        foreach ($members as $member) {
            if (self::memberUuid((string) $member) === $jobId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The uuid inside a serialised queue member, or null when it is not one.
     *
     * Laravel puts the uuid at the top level of the payload it decodes, both
     * when it pushes and when it reserves. `data.uuid` is checked too because
     * the nested payload carries its own copy, and either is the same job.
     */
    private static function memberUuid(string $member): ?string
    {
        $decoded = json_decode($member, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach ([$decoded['uuid'] ?? null, $decoded['data']['uuid'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The deploy log this task's username last wrote, when this task is the
     * one that wrote it. A task judged by an *older* deploy's log would adopt
     * a verdict that says nothing about its own work.
     *
     * A task that recorded the deploy it runs is matched by that id: a rebuild
     * or an archive deploy resumes the log a create left waiting for files,
     * which started long before the task did.
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
