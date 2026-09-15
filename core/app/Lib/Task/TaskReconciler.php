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
 * terminal status, because the writer is the thing that died. The row then
 * reads `running` for good and every poller waits on a job that no longer
 * exists.
 *
 * Observed on 2.29.1.58: a reboot at 15:34 left 8 tasks `running` with a null
 * pid, and a batch runner polled them for 25 minutes before anyone noticed
 * the work had died with the previous boot. `task:prune` cannot help -- it
 * skips non-terminal rows by design, since deleting an in-flight task would
 * be worse than a stuck one.
 *
 * **The queue is the evidence, not the deploy log.** A job keeps its row in
 * the `jobs` table while it is pending or reserved by a worker, and loses it
 * when it finishes. So "is this job still queued?" is answered by that table
 * directly, exactly, and with no window in which the answer is ambiguous.
 *
 * The read must go through the *payload*, not the uuid: the uuid is a field
 * inside it. A lookup keyed by the bare uuid silently misses every time --
 * which is what the first version of this class did against Redis, and it
 * retired nothing at all on 2.29.1.58 and 178.104.84.45. {@see queueCheck()}
 *
 * The deploy log looks like evidence and is not. It is written *by* the work,
 * so it cannot outlive it -- and it is deleted with the account on rollback,
 * which is the last thing every failed deploy does. An earlier version of
 * this class read a missing log as "the work died", and a sweep run by hand
 * with no grace period then cancelled two deploys that were mid-rollback and
 * very much alive (flowfuse, operationalco). A signal that is absent both
 * when a job died *and* when it is finishing normally cannot be the signal.
 *
 * The log is still worth reading once the queue has said the job is gone: its
 * terminal status is the deploy's own verdict, and adopting it beats
 * inventing one.
 *
 * Fails *closed* at every step. An unreadable queue, a non-deploy job with no
 * recorded pid, a job still reserved -- all leave the row alone. Retiring a
 * live deploy would tell a customer their deploy stopped while it runs.
 */
final class TaskReconciler
{
    public const ORPHAN_MESSAGE =
        'The deploy stopped without finishing — the engine process running it is gone. '
        . 'This usually means the host restarted or ran out of memory. Deploy again to retry.';

    /**
     * @param null|callable(Task): ?bool $isPending overrides the queue check
     * @return list<int> the ids it retired
     */
    public static function reconcile(
        bool $dryRun = false,
        ?int $olderThanSeconds = null,
        ?callable $isPending = null,
    ): array {
        $query = Task::query()->where('status', Task::STATUS_RUNNING);

        if ($olderThanSeconds !== null && $olderThanSeconds > 0) {
            // A task that started moments ago may be between `markRunning()`
            // and its job being pushed -- and is certainly still wanted.
            $query->where('started_at', '<', now()->subSeconds($olderThanSeconds));
        }

        $retired = [];
        foreach ($query->get() as $task) {
            $latest = self::latestDeployLog($task);
            $decision = self::decide($task, $latest, $isPending);

            if ($decision === null) {
                continue;
            }

            $retired[] = (int) $task->id;
            if ($dryRun) {
                continue;
            }

            self::apply($task, $decision, $latest);
        }

        return $retired;
    }

    /**
     * The status this task should have, or null to leave it running.
     *
     * @param array<string, mixed>|null $latest the deploy log, when one is readable
     * @param null|callable(Task): ?bool $isPending
     */
    public static function decide(Task $task, ?array $latest, ?callable $isPending = null): ?string
    {
        // A deploy log's terminal status is a real verdict, so it is adopted
        // rather than replaced, whatever the queue says -- the work is over
        // and the row merely never heard about it.
        if ($latest !== null && ($latest['status'] ?? null) !== DeployLogger::STATUS_RUNNING) {
            return match ($latest['status']) {
                DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL => Task::STATUS_COMPLETED,
                default => Task::STATUS_FAILED,
            };
        }

        $pending = ($isPending ?? self::queueCheck())($task);

        if ($pending !== false) {
            // true: still queued or reserved, so a worker still owes it an
            // answer. null: nothing could tell us, and "cannot tell" is not
            // evidence of death.
            return null;
        }

        // The job is not in the queue any more and no worker took it to the
        // end. For a non-deploy job that is all that can be said -- no log
        // exists to say more -- but it is enough.
        return Task::STATUS_CANCELLED;
    }

    /**
     * @param array<string, mixed>|null $latest
     */
    private static function apply(Task $task, string $status, ?array $latest): void
    {
        if ($status === Task::STATUS_COMPLETED) {
            $task->markCompleted();
            Log::warning('Task ' . $task->id . ' for ' . $task->username
                . ' was reconciled to completed from its deploy log.');

            return;
        }

        if ($status === Task::STATUS_FAILED) {
            // The deploy log recorded a real failure with its own reason.
            $reason = $latest['error'] ?? null;
            $task->markFailed(is_string($reason) && $reason !== '' ? $reason : self::ORPHAN_MESSAGE);

            return;
        }

        // Interrupted with no verdict anywhere. Not invented, and named as
        // what it is: `cancelled` is what this engine already means by
        // "stopped without a verdict".
        $task->details = array_merge($task->details ?? [], ['reconciled' => self::ORPHAN_MESSAGE]);
        $task->markCancelled();
        Log::warning('Task ' . $task->id . ' for ' . $task->username
            . ' was retired: its job is no longer in the queue and nothing finished it.');
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
     * @param null|callable(string): iterable<mixed> $payloads
     *        overrides the table read, so the check itself is testable
     * @return callable(Task): ?bool
     */
    public static function queueCheck(?callable $payloads = null): callable
    {
        return static function (Task $task) use ($payloads): ?bool {
            $jobId = $task->job_id;
            if (!is_string($jobId) || $jobId === '') {
                // No uuid was ever recorded (a job that died before a worker
                // took it). Nothing to look up.
                return null;
            }

            $queue = is_string($task->queue) && $task->queue !== '' ? $task->queue : 'default';

            try {
                // Bounded by the queue depth, so reading it whole is safe.
                $payloads ??= static fn (string $queue): iterable => DB::table(config('queue.connections.database.table') ?? 'jobs')
                    ->where('queue', $queue)
                    ->pluck('payload');

                $members = [];
                foreach ($payloads($queue) as $payload) {
                    $members[] = $payload;
                }

                return self::membersCarryJob($members, $jobId);
            } catch (\Throwable $e) {
                Log::warning('Could not read the queue to reconcile task ' . $task->id . ': ' . $e->getMessage());

                return null;
            }
        };
    }

    /**
     * Whether any serialised queue member is the given job.
     *
     * @param array<int, mixed> $members raw ZSET or list members
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
        if (!is_array($decoded)) {
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
     * @return array<string, mixed>|null
     */
    private static function latestDeployLog(Task $task): ?array
    {
        if (!$task->isDeploy()) {
            return null;
        }

        $username = $task->username;
        if (!is_string($username) || $username === '') {
            return null;
        }

        try {
            $latest = DeployLogger::readLatestFor($username);
        } catch (\Throwable $e) {
            Log::warning('Could not read the deploy log for ' . $username . ': ' . $e->getMessage());

            return null;
        }

        if (!is_array($latest)) {
            return null;
        }

        // Started well before this task did, so it belongs to an earlier
        // deploy. The minute of slack absorbs clock and ordering jitter.
        $startedFrom = $latest['started_at'] ?? null;
        if ($task->started_at !== null && is_numeric($startedFrom)
            && (int) $startedFrom < $task->started_at->getTimestamp() - 60) {
            return null;
        }

        return $latest;
    }
}
