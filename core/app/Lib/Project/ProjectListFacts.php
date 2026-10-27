<?php

namespace App\Lib\Project;

use App\Jobs\DeployProject;
use App\Lib\Host\ProjectStats;
use App\Models\Domain;
use App\Models\Task;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a project's `app` and `usage` blocks read from the database, loaded for
 * a whole list at once: one query per table, however many projects it holds.
 */
final class ProjectListFacts
{
    /**
     * @param  array<string, true>  $usernames  the projects loaded
     * @param  array<string, true>  $queuedDeploys  projects with a deploy task not finished yet
     * @param  array<int, Collection<int, Tunnel>>  $tunnels  by user id, oldest first
     * @param  array<int, Domain>  $mainDomains  by user id
     * @param  array<string, array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int, sampled_at: string}>  $usage  by username
     */
    private function __construct(
        private readonly array $usernames,
        private readonly array $queuedDeploys,
        private readonly array $tunnels,
        private readonly array $mainDomains,
        private readonly array $usage,
    ) {}

    /**
     * Projects not saved yet have nothing stored, so they cost no query.
     *
     * @param  iterable<User>  $users
     */
    public static function load(iterable $users): self
    {
        $saved = collect($users)->filter(static fn (User $user): bool => $user->exists)->keyBy('id');
        if ($saved->isEmpty()) {
            return new self([], [], [], [], []);
        }
        $usernames = $saved->map(static fn (User $user): string => (string) $user->username)->values()->all();

        return new self(
            array_fill_keys($usernames, true),
            self::queuedDeploys($usernames),
            self::tunnels($saved->keys()->all()),
            self::mainDomains($saved),
            ProjectStats::latestOf($usernames),
        );
    }

    public function covers(User $user): bool
    {
        return isset($this->usernames[(string) $user->username]);
    }

    /** Queued, not started yet: there is no deploy lock until a worker picks it up. */
    public function hasQueuedDeploy(User $user): bool
    {
        return isset($this->queuedDeploys[(string) $user->username]);
    }

    /** @return Collection<int, Tunnel> */
    public function tunnelsOf(User $user): Collection
    {
        return $this->tunnels[$user->id] ?? new Collection;
    }

    public function mainDomainOf(User $user): ?Domain
    {
        return $this->mainDomains[$user->id] ?? null;
    }

    /** @return ?array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int, sampled_at: string} */
    public function usageOf(User $user): ?array
    {
        return $this->usage[(string) $user->username] ?? null;
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, true>
     */
    private static function queuedDeploys(array $usernames): array
    {
        $queued = Task::query()
            ->whereIn('username', $usernames)
            ->where('job_type', DeployProject::class)
            ->whereNotIn('status', Task::TERMINAL_STATUSES)
            ->distinct()
            ->pluck('username')
            ->all();

        return array_fill_keys(array_map('strval', $queued), true);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Collection<int, Tunnel>>
     */
    private static function tunnels(array $userIds): array
    {
        return Tunnel::query()
            ->whereIn('user_id', $userIds)
            ->with('domain')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(static fn (Collection $tunnels): Collection => $tunnels->values())
            ->all();
    }

    /**
     * Each project's row for its own main domain.
     *
     * @param  Collection<int, User>  $users  by id
     * @return array<int, Domain>
     */
    private static function mainDomains(Collection $users): array
    {
        $names = $users->pluck('domain')->filter(static fn (mixed $d): bool => is_string($d) && $d !== '')->unique()->values()->all();
        if ($names === []) {
            return [];
        }

        $main = [];
        $rows = Domain::query()->whereIn('user_id', $users->keys()->all())->whereIn('domain', $names)->orderBy('id')->get();
        foreach ($rows as $domain) {
            if (! isset($main[$domain->user_id]) && $domain->domain === $users->get($domain->user_id)?->domain) {
                $main[$domain->user_id] = $domain;
            }
        }

        return $main;
    }
}
