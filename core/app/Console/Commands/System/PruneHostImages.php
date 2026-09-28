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

/**
 * Give back host disk the deploy path used and nobody is using any more:
 * base images outside the prewarm catalogue that no deploy has pulled, built or
 * named inside the window, and host build cache.
 *
 * What counts as used is {@see HostImageRetention}. Nothing runs while a
 * deploy is in flight, and a deploy that starts mid-run stops the removals:
 * pulling an image out from under a deploy that is about to load it is the
 * kind of interruption this must never cause.
 */
class PruneHostImages extends Command
{
    protected $signature = 'system:image:prune
        {--older-than= : Keep images pulled, built or named by a deploy within this window, e.g. 3d ("off" skips images; default DEPLOY_HOST_IMAGE_RETENTION)}
        {--build-cache-older-than= : Drop host build cache unused this long, e.g. 24h ("off" skips it; default DEPLOY_HOST_BUILD_CACHE_RETENTION)}
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

        $busy = $this->deploysInFlight();
        if ($busy !== []) {
            $this->warn('Deferred: deploy in flight for ' . implode(', ', $busy));

            return 0;
        }

        $system = app(System::class);
        $dryRun = (bool) $this->option('dry-run');
        if ($imageWindow !== null) {
            $this->pruneImages($system, $imageWindow, $dryRun);
        }
        if ($cacheWindow !== null) {
            $this->pruneBuildCache($system, $cacheWindow, $dryRun);
        }

        return 0;
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

    private function pruneImages(System $system, int $window, bool $dryRun): void
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
            $this->warn('Could not list host images, keeping them all: ' . $e->getMessage());

            return;
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
            $this->info('No host base image is unused for longer than ' . self::describe($window) . '.');

            return;
        }

        $table = [];
        $freed = 0;
        foreach ($remove as $ref => $image) {
            $action = $dryRun ? 'would remove' : 'removed';
            if (!$dryRun) {
                $busy = $this->deploysInFlight();
                if ($busy !== []) {
                    $this->warn('Stopped: a deploy started for ' . implode(', ', $busy));
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
        $this->info(sprintf('%s about %s of base images', $dryRun ? 'Would free' : 'Freed', HostPrewarmPlan::formatBytes($freed)));
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
            $this->info('Pruned host build cache unused for ' . self::describe($window));
        } catch (\Exception $e) {
            $this->warn('Could not prune the host build cache: ' . trim($e->getMessage()));
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
