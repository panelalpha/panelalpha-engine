<?php

namespace App\Lib\Task;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A task's job in the queue's `jobs` table: whether it is still pending or reserved, and dropping the row a dead
 * worker left reserved. Laravel deletes a row when its job finishes, and the uuid is matched by decoding the payload.
 */
final class TaskJobQueue
{
    /**
     * Null when the task never recorded a job uuid (a job that died before a worker took it)
     * or the queue cannot be read. The queue is read whole, which its depth bounds.
     *
     * @param  null|callable(string): iterable<mixed>  $payloads
     */
    public static function isQueued(Task $task, ?callable $payloads): ?bool
    {
        $jobId = $task->job_id;
        if (! is_string($jobId) || $jobId === '') {
            return null;
        }

        $queue = self::queueOf($task);

        try {
            return self::membersCarryJob(self::members($queue, $payloads), $jobId);
        } catch (\Throwable $e) {
            Log::warning('Could not read the queue to reconcile task '.$task->id.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  null|callable(string): iterable<mixed>  $payloads
     * @return list<mixed>
     */
    private static function members(string $queue, ?callable $payloads): array
    {
        $payloads ??= static fn (string $queue): iterable => DB::table(self::jobsTable())
            ->where('queue', $queue)
            ->pluck('payload');

        $members = [];
        foreach ($payloads($queue) as $payload) {
            $members[] = $payload;
        }

        return $members;
    }

    /**
     * Deleting the dead worker's reserved row is what stops a worker running the job's failed() a day later, against
     * whatever has its names by then (CreateStaging's destroys a re-created staging); the failed record is only a record.
     */
    public static function dropReservedJob(Task $task): void
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
                app('queue.failer')->log('database', $queue, (string) $row->payload, new \RuntimeException(TaskReconciler::ORPHAN_MESSAGE));
            }
        } catch (\Throwable $e) {
            Log::warning('Could not drop the queue row of retired task '.$task->id.': '.$e->getMessage());
        }
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

    private static function jobsTable(): string
    {
        return config('queue.connections.database.table') ?? 'jobs';
    }

    private static function queueOf(Task $task): string
    {
        return is_string($task->queue) && $task->queue !== '' ? $task->queue : 'default';
    }

    /**
     * The uuid inside a serialised queue member, or null when it is not one. Laravel puts it at the top level,
     * and the nested `data.uuid` is the same job's own copy.
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
}
