<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Task\TaskTrigger;
use App\Models\HookDelivery;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The deploys a project still has a log of, read from the logs themselves
 * and from the tasks and hook deliveries that name them.
 */
final class DeployHistory
{
    /** The status of a kept deploy whose log ends without a verdict. */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Every kept deploy, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(User $user): array
    {
        return self::entries($user, self::ids($user->username));
    }

    /**
     * One kept deploy, or null when its log is gone.
     *
     * @return array<string, mixed>|null
     */
    public static function find(User $user, string $id): ?array
    {
        if (! in_array($id, self::ids($user->username), true)) {
            return null;
        }

        return self::entries($user, [$id])[0];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private static function entries(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $latest = DeployLogger::readLatestFor($user->username) ?? [];
        $tasks = self::tasksByDeploy($user->username, $ids);
        $deliveries = self::deliveriesByDeploy($user, $ids);

        return array_map(
            static fn (string $id): array => self::entry($user->username, $id, $latest, $tasks->get($id), $deliveries->get($id)),
            $ids
        );
    }

    /**
     * Deploy ids start with the time they started, so they sort by it.
     *
     * @return list<string>
     */
    private static function ids(string $username): array
    {
        $ids = array_map(
            static fn (string $file): string => basename($file, DeployLogPaths::LOG_EXTENSION),
            (new DeployLogPaths($username))->logFiles()
        );
        rsort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $latest
     * @return array<string, mixed>
     */
    private static function entry(string $username, string $id, array $latest, ?Task $task, ?HookDelivery $delivery): array
    {
        $reader = new DeployLogReader((new DeployLogPaths($username))->log($id));
        $head = $reader->head(DeployStartLine::HEAD);
        $start = DeployStartLine::read($head);
        $outcome = ($latest['id'] ?? null) === $id ? self::latestOutcome($latest) : self::loggedOutcome($reader, $head);

        return [
            'id' => $id,
            ...$outcome,
            'kind' => $start['kind'],
            'source' => $start['source'],
            'branch' => $start['branch'] ?? $delivery?->branch,
            'commit' => $start['commit'] ?? self::taskText($task, 'commit') ?? $delivery?->commit,
            'trigger' => self::trigger($start['source'], $task, $delivery),
            'action' => self::taskText($task, 'action'),
            'task_id' => $task?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $latest
     * @return array{status: string, error: ?string, started_at: ?int, finished_at: ?int}
     */
    private static function latestOutcome(array $latest): array
    {
        return [
            'status' => is_string($latest['status'] ?? null) ? $latest['status'] : DeployLogger::STATUS_RUNNING,
            'error' => is_string($latest['error'] ?? null) ? $latest['error'] : null,
            'started_at' => isset($latest['started_at']) ? (int) $latest['started_at'] : null,
            'finished_at' => isset($latest['finished_at']) ? (int) $latest['finished_at'] : null,
        ];
    }

    /**
     * @param  list<array{ts: int, stage: ?string, level: string, msg: string}>  $head
     * @return array{status: string, error: ?string, started_at: ?int, finished_at: ?int}
     */
    private static function loggedOutcome(DeployLogReader $reader, array $head): array
    {
        $finish = DeployFinishLine::find($reader->last(DeployFinishLine::LOOKBACK));

        return [
            'status' => $finish['status'] ?? self::STATUS_UNKNOWN,
            'error' => $finish['error'] ?? null,
            'started_at' => isset($head[0]) ? $head[0]['ts'] : null,
            'finished_at' => $finish['ts'] ?? null,
        ];
    }

    private static function trigger(?string $source, ?Task $task, ?HookDelivery $delivery): ?string
    {
        if ($delivery !== null || $source === TaskTrigger::HOOK) {
            return TaskTrigger::HOOK;
        }

        return self::taskText($task, 'trigger');
    }

    private static function taskText(?Task $task, string $key): ?string
    {
        $value = $task?->details[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The newest task that ran each deploy: an archive upload continues the
     * deploy its create left waiting for files.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Task>
     */
    private static function tasksByDeploy(string $username, array $ids): Collection
    {
        return Task::query()
            ->where('username', $username)
            ->whereIn('job_type', Task::DEPLOY_JOB_TYPES)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Task $task): bool => in_array($task->details['deploy_id'] ?? null, $ids, true))
            ->keyBy(static fn (Task $task): string => $task->details['deploy_id']);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, HookDelivery>
     */
    private static function deliveriesByDeploy(User $user, array $ids): Collection
    {
        return HookDelivery::query()
            ->whereIn('deploy_id', $ids)
            ->whereHas('hook', static fn ($hook) => $hook->where('user_id', $user->getKey()))
            ->get()
            ->keyBy('deploy_id');
    }
}
