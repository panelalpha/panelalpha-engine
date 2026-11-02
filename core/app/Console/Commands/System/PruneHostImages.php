<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\HostImageRetention;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\ProjectCache;
use App\Models\OperatorImage;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind\HostDiskGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give back host disk the deploy path used and nobody is using any more:
 * base images outside the prewarm catalogue that no deploy has pulled, built or
 * named inside the window, their tags in panelalpha-cache-registry, and host
 * build cache.
 *
 * What counts as used is {@see HostImageRetention}. No image is removed while a
 * deploy is in flight, and a deploy that starts mid-run stops the removals:
 * pulling an image out from under a deploy that is about to load it is the
 * kind of interruption this must never cause. A deferred run is retried by
 * the next hourly one (`--due-after`).
 *
 * `--until-free` is the disk guard's: a deploy in flight keeps only what its log
 * names, and the window halves until enough is free ({@see pruneUntilFree()}).
 */
class PruneHostImages extends Command
{
    protected $signature = 'system:image:prune
        {--older-than= : Keep images pulled, built or named by a deploy within this window, e.g. 3d ("off" skips images; default DEPLOY_HOST_IMAGE_RETENTION)}
        {--build-cache-older-than= : Drop host build cache unused this long, e.g. 24h ("off" skips it; default DEPLOY_HOST_BUILD_CACHE_RETENTION)}
        {--due-after= : Skip the image half when it last completed within this window, e.g. 20h (the schedule retries hourly)}
        {--until-free= : Repeat the image half, halving the window down to any age, until the Docker root has this much free, e.g. 15% or 35G}
        {--dry-run : Print what would be removed and exit}';

    protected $description = 'Remove host base images and build cache no deploy has used recently';

    /** Under this a pressure pass takes every image nothing holds, whatever its age. */
    public const MIN_PRESSURE_WINDOW = 900;

    public function handle(): int
    {
        try {
            $imageWindow = self::window(
                $this->option('older-than') ?? config('deploy.host_image_retention'),
                HostImageRetention::DEFAULT_MAX_AGE
            );
            $cacheWindow = self::window(
                $this->option('build-cache-older-than') ?? config('deploy.host_build_cache_retention'),
                HostImageRetention::DEFAULT_BUILD_CACHE_MAX_AGE
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if ($imageWindow === null && $cacheWindow === null) {
            $this->info('Host image prune is off.');

            return 0;
        }

        $system = app(System::class);
        $dryRun = (bool) $this->option('dry-run');
        // Never deferred: an age filter cannot reach cache a running build holds.
        if ($cacheWindow !== null) {
            $this->pruneBuildCache($system, $cacheWindow, $dryRun);
        }
        if ($imageWindow === null) {
            return 0;
        }

        $untilFree = $this->option('until-free');
        if (is_string($untilFree) && trim($untilFree) !== '') {
            $this->pruneUntilFree($system, $imageWindow, $untilFree, $dryRun);

            return 0;
        }

        try {
            $due = self::window($this->option('due-after') ?? 'off', '0');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $last = self::lastCompleted();
        if ($due !== null && $last !== null && time() - $last < $due) {
            $this->line('Image prune not due: last completed '.self::describe(time() - $last).' ago.');

            return 0;
        }

        $busy = $this->deploysInFlight();
        if ($busy !== []) {
            $this->report('warning', 'Deferred: deploy in flight for '.implode(', ', $busy));

            return 0;
        }

        if ($this->pruneImages($system, $imageWindow, $dryRun) && ! $dryRun) {
            self::recordCompleted(time());
        }

        return 0;
    }

    /** When the image half last ran to the end, from the state file. */
    public static function lastCompleted(): ?int
    {
        $state = json_decode((string) @file_get_contents(self::statePath()), true);
        $at = is_array($state) ? ($state['completed_at'] ?? null) : null;

        return is_int($at) ? $at : null;
    }

    public static function recordCompleted(int $at): void
    {
        @file_put_contents(self::statePath(), (string) json_encode(['completed_at' => $at]));
    }

    private static function statePath(): string
    {
        return storage_path('app/host-image-prune.json');
    }

    /** Console and the Laravel log: scheduled output goes nowhere. */
    private function report(string $level, string $message): void
    {
        $level === 'warning' ? $this->warn($message) : $this->info($message);
        Log::log($level, 'system:image:prune: '.$message);
    }

    /**
     * Seconds, or null for off.
     *
     * @throws \InvalidArgumentException
     */
    public static function window(mixed $value, string $default): ?int
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if (in_array($value, ['off', 'none', 'false', '0'], true)) {
            return null;
        }
        $seconds = ProjectCache::parseDuration($value === '' ? $default : $value);

        return $seconds !== null && $seconds > 0 ? $seconds : null;
    }

    /**
     * Halves the image window, down to any age, until the Docker root has $wanted
     * free or only what a deploy, container, prewarm or family holds is left.
     */
    private function pruneUntilFree(System $system, int $window, string $wanted, bool $dryRun): void
    {
        $guard = new HostDiskGuard($system, null);
        for ($pass = $window; ; $pass = self::nextWindow($pass)) {
            $this->line($pass === 0 ? 'Images nothing holds, of any age:' : 'Images unused for '.self::describe($pass).':');
            if (! $this->pruneImages($system, $pass, $dryRun, true)) {
                return;
            }
            $usage = $guard->usage(HostDiskGuard::DOCKER_ROOT);
            $target = $usage === null ? null : GuardHostDisk::target($wanted, $usage['size']);
            if ($usage === null || $target === null) {
                $this->report('warning', 'Could not read free space on '.HostDiskGuard::DOCKER_ROOT.'; no further pass.');

                return;
            }
            $state = sprintf(
                '%s free on %s, target %s',
                HostPrewarmPlan::formatBytes($usage['free']),
                HostDiskGuard::DOCKER_ROOT,
                HostPrewarmPlan::formatBytes($target)
            );
            if ($usage['free'] >= $target) {
                $this->report('info', $state.'.');

                return;
            }
            if ($pass === 0) {
                $this->report('warning', $state.'; every image left is in use or the newest of its kind.');

                return;
            }
            if ($dryRun) {
                $this->line($state.'; would go on with a shorter window.');

                return;
            }
        }
    }

    /** Half of $window, or 0 once that is under {@see MIN_PRESSURE_WINDOW}. */
    public static function nextWindow(int $window): int
    {
        $half = intdiv($window, 2);

        return $half < self::MIN_PRESSURE_WINDOW ? 0 : $half;
    }

    /**
     * True when it ran to the end; false when it could not list or a deploy stopped it.
     * Under $pressure a deploy in flight stops nothing: what its log names is kept.
     */
    private function pruneImages(System $system, int $window, bool $dryRun, bool $pressure = false): bool
    {
        try {
            $listed = HostImageRetention::parseImageList($system->exec(
                Privileged::docker()->imageNamesWithSizes(),
                [],
                60
            ));
            // What a container uses has to be known; without it nothing is safe to judge.
            $containers = DindAccountCleanup::parseImageRefLines($system->exec(
                Privileged::docker()->containerImages(),
                [],
                60
            ));
        } catch (\Exception $e) {
            $this->report('warning', 'Could not list host images, keeping them all: '.$e->getMessage());

            return false;
        }

        $catalog = ImageCatalog::all();
        $operatorRefs = OperatorImage::refs();
        if ($operatorRefs === null) {
            $this->report('warning', 'Could not read the operator images, keeping every host image.');

            return false;
        }
        $prewarmed = array_merge(array_map(
            static fn (array $item): string => (string) $item['ref'],
            HostPrewarmPlan::catalog()
        ), $operatorRefs);

        $inScope = array_values(array_filter(
            $listed,
            static fn (array $row): bool => HostImageRetention::inScope(HostImageRetention::logicalRef($row['tag']), $catalog)
        ));
        $since = $this->tagTimes($system, array_column($inScope, 'tag'));
        $rows = array_map(
            static fn (array $row): array => $row + ['since' => $since[$row['tag']] ?? null],
            $inScope
        );

        $now = time();
        $candidates = HostImageRetention::candidates($rows, $prewarmed, $catalog, $containers, $now, $window);
        $named = $this->namedByDeployLogs(array_keys($candidates), $now, $window);
        $remove = array_diff_key($candidates, array_flip($named));
        if ($remove === []) {
            $this->report('info', 'No host base image is unused for longer than '.self::describe($window).'.');
            $this->pruneRegistry($system, $prewarmed, $catalog, $containers, $now, $window, $dryRun, $pressure);

            return true;
        }

        $table = [];
        $freed = 0;
        $completed = true;
        foreach ($remove as $ref => $image) {
            $action = $dryRun ? 'would remove' : 'removed';
            if (! $dryRun) {
                if ($pressure) {
                    if ($this->namedByDeploysInFlight([$ref]) !== []) {
                        continue;
                    }
                } elseif (($busy = $this->deploysInFlight()) !== []) {
                    $this->report('warning', 'Stopped: a deploy started for '.implode(', ', $busy));
                    $completed = false;
                    break;
                }
                try {
                    // No -f: the daemon still refuses an image a container holds.
                    $system->exec(Privileged::docker()->removeImages(...$image['tags']), [], 120);
                } catch (\Exception $e) {
                    $action = 'failed';
                    $this->warn("Could not remove {$ref}: ".trim($e->getMessage()));
                }
            }
            if ($action !== 'failed') {
                $freed += (int) $image['bytes'];
            }
            $table[] = [
                $ref,
                self::describe(max(0, $now - $image['since'])).' ago',
                HostPrewarmPlan::formatBytes($image['bytes']),
                $action,
            ];
        }

        $this->table(['Image', 'Last pulled or built', 'Size', ''], $table);
        $this->report('info', sprintf('%s about %s of base images', $dryRun ? 'Would free' : 'Freed', HostPrewarmPlan::formatBytes($freed)));
        if ($completed) {
            $this->pruneRegistry($system, $prewarmed, $catalog, $containers, $now, $window, $dryRun, $pressure);
        }

        return $completed;
    }

    /**
     * The same rule for panelalpha-cache-registry, by when a tag was last pushed. No running app loses an
     * image: an account keeps its own copy, and a deploy that misses gets it from the host or builds it.
     *
     * @param  list<string>  $prewarmed
     * @param  list<string>  $catalog
     * @param  list<string>  $containers
     */
    private function pruneRegistry(
        System $system,
        array $prewarmed,
        array $catalog,
        array $containers,
        int $now,
        int $window,
        bool $dryRun,
        bool $pressure
    ): void {
        try {
            $tags = CacheRegistry::parseTagListing($system->exec(CacheRegistry::tagListingArgv(), [], 120));
        } catch (\Exception $e) {
            $this->report('warning', 'Could not list the cache registry, keeping it as it is: '.trim($e->getMessage()));

            return;
        }
        $rows = [];
        foreach ($tags as $ref => $tag) {
            if (HostImageRetention::inScope($ref, $catalog)) {
                $rows[] = ['tag' => $ref, 'bytes' => null, 'since' => $tag['pushed']];
            }
        }
        $candidates = HostImageRetention::candidates($rows, $prewarmed, $catalog, $containers, $now, $window);
        $named = $this->namedByDeployLogs(array_keys($candidates), $now, $window);
        $chosen = array_keys(array_diff_key($candidates, array_flip($named)));
        $digests = array_map(static fn (array $tag): string => $tag['digest'], $tags);
        $remove = CacheRegistry::unwanted($digests, array_values(array_diff(array_keys($tags), $chosen)));
        if ($remove === []) {
            return;
        }
        if ($dryRun) {
            foreach ($remove as $ref) {
                $this->line("would remove from the cache registry: {$ref}");
            }

            return;
        }
        if (! $pressure && ($busy = $this->deploysInFlight()) !== []) {
            $this->report('warning', 'Cache registry left as it is: a deploy started for '.implode(', ', $busy));

            return;
        }

        $this->withPushLock(function () use ($system, $remove, $digests, $pressure): void {
            $registry = app(CacheRegistry::class);
            $deleted = 0;
            foreach ($remove as $ref) {
                if ($pressure && $this->namedByDeploysInFlight([$ref]) !== []) {
                    continue;
                }
                if ($registry->delete($ref, $digests[$ref])) {
                    $deleted++;
                    $this->line("removed from the cache registry: {$ref}");
                } else {
                    $this->warn("Could not remove {$ref} from the cache registry");
                }
            }
            if ($deleted > 0) {
                $system->exec(DindImageStore::garbageCollectArgv(), [], 1800);
                $registry->removeEmptyRepositories($system);
                $this->report('info', "Removed {$deleted} tag(s) from the cache registry and garbage-collected it");
            }
        });
    }

    /**
     * Holds {@see DindImageStore::PUSH_LOCK} exclusive, as a garbage-collect deletes an unfinished upload's
     * layers. A host that never frees it within five minutes is left alone.
     */
    private function withPushLock(callable $fn): void
    {
        $path = DindImageStore::pushLock();
        if (! file_exists($path)) {
            @touch($path);
        }
        $lock = @fopen($path, 'r');
        $deadline = time() + 300;
        while ($lock !== false && ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($lock);
                $lock = false;
                break;
            }
            sleep(1);
        }
        if ($lock === false) {
            $this->report('warning', "Could not lock {$path}; leaving the cache registry as it is.");

            return;
        }

        try {
            $fn();
        } catch (\Exception $e) {
            $this->report('warning', 'Cache registry garbage-collect failed: '.trim($e->getMessage()));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * LastTagTime per tag, one inspect call per batch. A batch that fails
     * leaves its tags without a time, which keeps them.
     *
     * @param  list<string>  $tags
     * @return array<string, ?int>
     */
    private function tagTimes(System $system, array $tags): array
    {
        $times = [];
        foreach (array_chunk($tags, 50) as $batch) {
            try {
                $out = $system->exec(
                    Privileged::docker()->imagesLastTagTime(...$batch),
                    [],
                    60
                );
            } catch (\Exception $e) {
                continue;
            }
            $lines = preg_split('/\r?\n/', trim($out)) ?: [];
            if (count($lines) !== count($batch)) {
                continue;
            }
            foreach ($batch as $i => $tag) {
                $times[$tag] = HostImageRetention::parseTagTime($lines[$i]);
            }
        }

        return $times;
    }

    /**
     * Candidates a deploy log still names: an account's latest deploy (the
     * build its app runs), or any deploy logged inside the window.
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    private function namedByDeployLogs(array $refs, int $now, int $window): array
    {
        $named = [];
        foreach (DeployLogArchive::users() as $account) {
            if ($refs === []) {
                break;
            }
            $username = (string) $account['username'];
            foreach (DeployLogArchive::deploys($username) as $deploy) {
                if (! $deploy['is_latest'] && $deploy['mtime'] < $now - $window) {
                    continue;
                }
                $log = @file_get_contents((new DeployLogPaths($username))->log($deploy['id']));
                if (! is_string($log) || $log === '') {
                    continue;
                }
                $found = HostImageRetention::mentionedIn($log, $refs);
                $named = array_merge($named, $found);
                $refs = array_values(array_diff($refs, $found));
                if ($refs === []) {
                    break;
                }
            }
        }

        return $named;
    }

    /**
     * Which of $refs a deploy in flight has named so far. Read again right
     * before each removal under pressure, so one that started mid-run counts.
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    private function namedByDeploysInFlight(array $refs): array
    {
        $named = [];
        foreach ($this->deploysInFlight() as $username) {
            foreach (DeployLogArchive::deploys($username) as $deploy) {
                if (! $deploy['is_latest']) {
                    continue;
                }
                $log = @file_get_contents((new DeployLogPaths($username))->log($deploy['id']));
                if (is_string($log) && $log !== '') {
                    $named = array_merge($named, HostImageRetention::mentionedIn($log, $refs));
                }
            }
        }

        return array_values(array_unique($named));
    }

    /**
     * @return list<string>
     */
    private function deploysInFlight(): array
    {
        $busy = [];
        foreach (DeployLogArchive::users() as $account) {
            $username = (string) $account['username'];
            try {
                if (DeployLogger::isLockedFor($username)) {
                    $busy[] = $username;
                }
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }

        return $busy;
    }

    private function pruneBuildCache(System $system, int $window, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would prune host build cache unused for '.self::describe($window));

            return;
        }
        try {
            $system->exec(
                Privileged::docker()->pruneBuildCacheUnusedFor($window),
                [],
                300
            );
            $this->report('info', 'Pruned host build cache unused for '.self::describe($window));
        } catch (\Exception $e) {
            $this->report('warning', 'Could not prune the host build cache: '.trim($e->getMessage()));
        }
    }

    private static function describe(int $seconds): string
    {
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1).'d';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1).'h';
        }

        return max(0, (int) round($seconds / 60)).'m';
    }
}
