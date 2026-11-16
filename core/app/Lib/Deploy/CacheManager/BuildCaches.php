<?php

namespace App\Lib\Deploy\CacheManager;

use App\Exceptions\ProblemException;
use App\Jobs\BuildRepositoryCache;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\Task;
use RuntimeException;

/**
 * Lists, queues and removes the registry's {@see BuildCache}s for the API, MCP and `pae system:build-cache:*`.
 */
final class BuildCaches
{
    public const REFUSED = 'build_cache_repository_refused';

    public const BUSY = 'build_cache_busy';

    public const REGISTRY_UNREADABLE = 'build_cache_registry_unreadable';

    public function __construct(private readonly CacheRegistry $registry) {}

    public static function make(): self
    {
        return new self(new CacheRegistry);
    }

    /**
     * @return list<array{ref: string, repository: string, tag: string}>
     *
     * @throws RuntimeException when the cache registry cannot be listed
     */
    public function all(): array
    {
        $caches = array_values(array_filter($this->refs(), BuildCache::isCacheRef(...)));
        sort($caches);

        return array_map(static function (string $ref): array {
            $at = strrpos($ref, ':');

            return [
                'ref' => $ref,
                'repository' => substr($ref, strlen(BuildCache::PREFIX) + 1, (int) $at - strlen(BuildCache::PREFIX) - 1),
                'tag' => substr($ref, (int) $at + 1),
            ];
        }, $caches);
    }

    /**
     * Queue the build of $repository's caches, on $branch or its default
     * branch, as a task the caller can follow.
     *
     * @throws ProblemException when the URL cannot have a shared cache, or that build is already queued
     */
    public function queueBuild(string $repository, ?string $branch): Task
    {
        $path = self::publicPath($repository);
        $branch = self::branch($branch);
        foreach (Task::query()->where('job_type', BuildRepositoryCache::class)
            ->whereIn('status', [Task::STATUS_QUEUED, Task::STATUS_RUNNING])->get() as $task) {
            if (($task->details['repository_path'] ?? null) === $path && ($task->details['branch'] ?? null) === $branch) {
                throw ProblemException::conflict('repository', self::BUSY, "Task {$task->id} is already building this repository's caches; wait for it to finish.");
            }
        }

        $task = Task::start(
            jobType: BuildRepositoryCache::class,
            queue: 'default',
            details: ['action' => 'build_cache_build', 'repository' => trim($repository), 'repository_path' => $path, 'branch' => $branch],
        );
        BuildRepositoryCache::dispatch(trim($repository), $branch)->attachTask($task);

        return $task;
    }

    /**
     * Remove $repository's caches, of one branch or of all of them. The
     * layers go at the next garbage-collect.
     *
     * @return list<string> the refs removed
     *
     * @throws ProblemException when the URL cannot have a shared cache
     * @throws RuntimeException when the cache registry cannot be listed or locked
     */
    public function remove(string $repository, ?string $branch): array
    {
        $path = self::publicPath($repository);
        $branch = self::branch($branch);
        $mine = array_values(array_filter($this->refs(), static fn (string $ref): bool => self::belongs($ref, $path, $branch)));
        if ($mine === []) {
            return [];
        }

        $removed = [];
        $locked = CacheRegistryLock::exclusive(function () use ($mine, &$removed): void {
            foreach ($mine as $ref) {
                $digest = $this->registry->digest($ref);
                if ($digest !== null && $this->registry->delete($ref, $digest)) {
                    $removed[] = $ref;
                }
            }
        });
        if (! $locked) {
            throw new RuntimeException('Could not lock '.DindImageStore::pushLock().'; nothing removed.');
        }

        return $removed;
    }

    /** $ref is a cache of $path, of $branch when one is given: its tag, or that tag with a build's hash. */
    public static function belongs(string $ref, string $path, ?string $branch): bool
    {
        $prefix = BuildCache::PREFIX.'/'.$path.':';
        if (! str_starts_with($ref, $prefix)) {
            return false;
        }
        if ($branch === null) {
            return true;
        }

        return preg_match('/^'.preg_quote((string) BuildCache::tag($branch), '/').'(-f[0-9a-f]{8})?$/', substr($ref, strlen($prefix))) === 1;
    }

    /**
     * @throws ProblemException
     */
    private static function publicPath(string $repository): string
    {
        $path = BuildCache::repositoryPath($repository);
        if ($path === null) {
            throw ProblemException::one(
                'repository',
                self::REFUSED,
                'Only a public https clone URL, with no user, token, port or query, can have a shared build cache.'
            );
        }

        return $path;
    }

    private static function branch(?string $branch): ?string
    {
        $branch = trim((string) $branch);

        return $branch === '' ? null : $branch;
    }

    /**
     * @return list<string>
     */
    private function refs(): array
    {
        $refs = $this->registry->refs();
        if ($refs === null) {
            throw new RuntimeException('Could not list the cache registry.');
        }

        return $refs;
    }
}
