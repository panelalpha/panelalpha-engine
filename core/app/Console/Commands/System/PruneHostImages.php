<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\HostImageRetention;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\ProjectCache;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give back host disk the deploy path used and nobody is using any more:
 * base images outside the prewarm catalogue that no deploy has pulled, built or
 * named inside the window, and host build cache.
 *
 * What counts as used is {@see HostImageRetention}. No image is removed while a
 * deploy is in flight, and a deploy that starts mid-run stops the removals:
 * pulling an image out from under a deploy that is about to load it is the
 * kind of interruption this must never cause. A deferred run is retried by
 * the next hourly one (`--due-after`).
 */
class PruneHostImages extends Command
{
    protected $signature = 'system:image:prune
        {--older-than= : Keep images pulled, built or named by a deploy within this window, e.g. 3d ("off" skips images; default DEPLOY_HOST_IMAGE_RETENTION)}
        {--build-cache-older-than= : Drop host build cache unused this long, e.g. 24h ("off" skips it; default DEPLOY_HOST_BUILD_CACHE_RETENTION)}
        {--due-after= : Skip the image half when it last completed within this window, e.g. 20h (the schedule retries hourly)}
        {--dry-run : Print what would be removed and exit}';

    protected $description = 'Remove host base images and build cache no deploy has used recently';

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

        try {
            $due = self::window($this->option('due-after') ?? 'off', '0');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $last = self::lastCompleted();
        if ($due !== null && $last !== null && time() - $last < $due) {
            $this->line('Image prune not due: last completed ' . self::describe(time() - $last) . ' ago.');

            return 0;
        }

        $busy = $this->deploysInFlight();
        if ($busy !== []) {
            $this->report('warning', 'Deferred: deploy in flight for ' . implode(', ', $busy));

            return 0;
        }

        if ($this->pruneImages($system, $imageWindow, $dryRun) && !$dryRun) {
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
        Log::log($level, 'system:image:prune: ' . $message);
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

    /** True when it ran to the end; false when it could not list or a deploy stopped it. */
    private function pruneImages(System $system, int $window, bool $dryRun): bool
    {
        try {
            $listed = HostImageRetention::parseImageList($system->exec(
                ['sudo', 'docker', 'images', '--format', '{{.Repository}}:{{.Tag}}\t{{.Size}}'],
                [],
                60
            ));
            // What a container uses has to be known; without it nothing is safe to judge.
            $containers = DindAccountCleanup::parseImageRefLines($system->exec(
                ['sudo', 'docker', 'ps', '-a', '--format', '{{.Image}}'],
                [],
                60
            ));
        } catch (\Exception $e) {
            $this->report('warning', 'Could not list host images, keeping them all: ' . $e->getMessage());

            return false;
        }

        $catalog = ImageCatalog::all();
        $prewarmed = array_map(
            static fn (array $item): string => (string) $item['ref'],
            HostPrewarmPlan::catalog()
        );

        $inScope = array_values(array_filter(
            $listed,
            static fn (array $row): bool
                => HostImageRetention::inScope(HostImageRetention::logicalRef($row['tag']), $catalog)
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
            $this->report('info', 'No host base image is unused for longer than ' . self::describe($window) . '.');

            return true;
        }

        $table = [];
        $freed = 0;
        $completed = true;
        foreach ($remove as $ref => $image) {
            $action = $dryRun ? 'would remove' : 'removed';
            if (!$dryRun) {
                $busy = $this->deploysInFlight();
                if ($busy !== []) {
                    $this->report('warning', 'Stopped: a deploy started for ' . implode(', ', $busy));
                    $completed = false;
                    break;
                }
                try {
                    // No -f: the daemon still refuses an image a container holds.
                    $system->exec(['sudo', 'docker', 'rmi', ...$image['tags']], [], 120);
                } catch (\Exception $e) {
                    $action = 'failed';
                    $this->warn("Could not remove {$ref}: " . trim($e->getMessage()));
                }
            }
            if ($action !== 'failed') {
                $freed += (int) $image['bytes'];
            }
            $table[] = [
                $ref,
                self::describe(max(0, $now - $image['since'])) . ' ago',
                HostPrewarmPlan::formatBytes($image['bytes']),
                $action,
            ];
        }

        $this->table(['Image', 'Last pulled or built', 'Size', ''], $table);
        $this->report('info', sprintf('%s about %s of base images', $dryRun ? 'Would free' : 'Freed', HostPrewarmPlan::formatBytes($freed)));

        return $completed;
    }

    /**
     * LastTagTime per tag, one inspect call per batch. A batch that fails
     * leaves its tags without a time, which keeps them.
     *
     * @param list<string> $tags
     * @return array<string, ?int>
     */
    private function tagTimes(System $system, array $tags): array
    {
        $times = [];
        foreach (array_chunk($tags, 50) as $batch) {
            try {
                $out = $system->exec(
                    ['sudo', 'docker', 'image', 'inspect', '--format', '{{json .Metadata.LastTagTime}}', '--', ...$batch],
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
     * @param list<string> $refs
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
                if (!$deploy['is_latest'] && $deploy['mtime'] < $now - $window) {
                    continue;
                }
                $log = @file_get_contents((new DeployLogPaths($username))->log($deploy['id']));
                if (!is_string($log) || $log === '') {
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
            $this->line('Would prune host build cache unused for ' . self::describe($window));

            return;
        }
        try {
            $system->exec(
                ['sudo', 'docker', 'buildx', 'prune', '-af', '--filter', "until={$window}s"],
                [],
                300
            );
            $this->report('info', 'Pruned host build cache unused for ' . self::describe($window));
        } catch (\Exception $e) {
            $this->report('warning', 'Could not prune the host build cache: ' . trim($e->getMessage()));
        }
    }

    private static function describe(int $seconds): string
    {
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1) . 'd';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1) . 'h';
        }

        return max(0, (int) round($seconds / 60)) . 'm';
    }
}
