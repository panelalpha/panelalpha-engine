<?php

namespace App\Lib\Task;

use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Retention for Task handles and their poll-buffer lines.
 *
 * The file deploy log is the archive ({@see \App\Lib\Deploy\DeployLog\DeployLogArchive}).
 * This keeps a bounded set of terminal Tasks so GET /tasks/{id} still answers
 * for recent jobs, and drops `dim` (subprocess spam) once the job is finished.
 */
final class TaskPruner
{
    public const KEEP_DEPLOY = 50;

    public const KEEP_OTHER = 200;

    public const MAX_LINES = 2000;

    public const DIM_LEVEL = 'dim';

    /**
     * @return array{deleted_tasks: int, trimmed_logs: int}
     */
    public static function prune(
        ?string $username = null,
        int $keepDeploy = self::KEEP_DEPLOY,
        int $keepOther = self::KEEP_OTHER,
        int $maxLines = self::MAX_LINES,
        bool $dryRun = false,
    ): array {
        self::assertPositive('keep', $keepDeploy);
        self::assertPositive('keep-other', $keepOther);
        self::assertPositive('max-lines', $maxLines);

        $oldIds = self::oldTaskIds($username, $keepDeploy, $keepOther);
        if (!$dryRun && $oldIds !== []) {
            foreach (array_chunk($oldIds, 100) as $chunk) {
                Task::query()->whereIn('id', $chunk)->delete();
            }
        }
        $trimmedLogs = self::trimRemaining($username, $maxLines, $dryRun, $oldIds);

        return [
            'deleted_tasks' => count($oldIds),
            'trimmed_logs' => $trimmedLogs,
        ];
    }

    public static function keepForJobType(string $jobType, int $keepDeploy = self::KEEP_DEPLOY, int $keepOther = self::KEEP_OTHER): int
    {
        return in_array($jobType, Task::DEPLOY_JOB_TYPES, true) ? $keepDeploy : $keepOther;
    }

    /**
     * Drop dim lines and cap the tail on one terminal Task. No-op while running.
     */
    public static function trimTask(Task $task, int $maxLines = self::MAX_LINES, bool $dryRun = false): int
    {
        self::assertPositive('max-lines', $maxLines);

        if (!$task->isTerminal()) {
            return 0;
        }

        $rows = TaskLog::query()
            ->where('task_id', $task->id)
            ->orderBy('id')
            ->get(['id', 'log']);

        $dimIds = [];
        $keptIds = [];
        foreach ($rows as $row) {
            if (self::isDim((string) $row->log)) {
                $dimIds[] = (int) $row->id;
            } else {
                $keptIds[] = (int) $row->id;
            }
        }

        $overflowIds = [];
        $overflow = count($keptIds) - $maxLines;
        if ($overflow > 0) {
            $overflowIds = array_slice($keptIds, 0, $overflow);
        }

        $deleteIds = array_values(array_unique(array_merge($dimIds, $overflowIds)));
        if ($deleteIds === [] || $dryRun) {
            return count($deleteIds);
        }

        foreach (array_chunk($deleteIds, 500) as $chunk) {
            TaskLog::query()->whereIn('id', $chunk)->delete();
        }

        return count($deleteIds);
    }

    /**
     * @return list<int>
     */
    private static function oldTaskIds(?string $username, int $keepDeploy, int $keepOther): array
    {
        $oldIds = [];
        foreach (self::buckets($username) as $bucket) {
            $keep = self::keepForJobType($bucket['job_type'], $keepDeploy, $keepOther);
            $ids = self::terminalIds($bucket['username'], $bucket['job_type']);
            array_push($oldIds, ...array_slice($ids, $keep));
        }

        return $oldIds;
    }

    /**
     * @param  list<int>  $excludeIds
     */
    private static function trimRemaining(?string $username, int $maxLines, bool $dryRun, array $excludeIds): int
    {
        $query = Task::query()
            ->whereIn('status', Task::TERMINAL_STATUSES)
            ->orderBy('id');
        self::scopeUsername($query, $username);
        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        $trimmed = 0;
        foreach ($query->cursor() as $task) {
            $trimmed += self::trimTask($task, $maxLines, $dryRun);
        }

        return $trimmed;
    }

    /**
     * @return list<array{username: ?string, job_type: string}>
     */
    private static function buckets(?string $username): array
    {
        $query = Task::query()
            ->whereIn('status', Task::TERMINAL_STATUSES)
            ->select('username', 'job_type')
            ->distinct();
        self::scopeUsername($query, $username);

        $buckets = [];
        foreach ($query->get() as $row) {
            $buckets[] = [
                'username' => $row->username,
                'job_type' => (string) $row->job_type,
            ];
        }

        return $buckets;
    }

    /**
     * Newest first (highest id).
     *
     * @return list<int>
     */
    private static function terminalIds(?string $username, string $jobType): array
    {
        $query = Task::query()
            ->where('job_type', $jobType)
            ->whereIn('status', Task::TERMINAL_STATUSES)
            ->orderByDesc('id');

        if ($username === null) {
            $query->whereNull('username');
        } else {
            $query->where('username', $username);
        }

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  Builder<Task>  $query
     */
    private static function scopeUsername(Builder $query, ?string $username): void
    {
        if ($username === null) {
            return;
        }

        $query->where('username', $username);
    }

    private static function isDim(string $raw): bool
    {
        $decoded = json_decode($raw, true);

        // A failed deploy's copied tail stays: its deploy log is gone.
        return is_array($decoded)
            && ($decoded['level'] ?? null) === self::DIM_LEVEL
            && !DeployLogTail::isKept($decoded);
    }

    private static function assertPositive(string $name, int $value): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($name . ' must be >= 1');
        }
    }
}
