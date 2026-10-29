<?php

namespace App\Console\Commands\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\ProjectCache;
use App\Models\User;
use App\System;
use Illuminate\Console\Command;

/**
 * Delete the host caches of projects that have not deployed recently.
 *
 * These caches exist only to make the *next* deploy fast -- `git clone` wipes
 * ~/project on every deploy, so vendor/ and node_modules are rebuilt every
 * time, and the caches are what keep that off packagist and the npm registry.
 * Nothing mounts them into a running application: once a deploy finishes they
 * are read by nobody until the next one.
 *
 * Which makes the arithmetic bad for the common hosting case. A site deployed
 * once and then served for months holds its caches -- ~306MB for a Laravel
 * skeleton with a Vite front end -- for exactly as long as it never reads
 * them, and until this existed nothing ever reclaimed them: disk-pressure
 * reclaim works on the account's inner Docker, and account teardown only fires
 * when the account is deleted.
 *
 * The whole directory goes, not just the download caches. Keeping node_modules
 * would leave ~65MB per project accumulating forever, which is the problem
 * rather than a smaller version of it. The cost is that a project redeploying
 * after the window pays a full install -- which it would have paid anyway,
 * since reusing node_modules needs a byte-identical lockfile and a project
 * that has not deployed in a long time is unlikely to have one.
 *
 * The work itself is {@see ProjectCache::PRUNE_SCRIPT}; this decides the
 * window, protects deploys in flight, and reports.
 */
class PruneProjectCaches extends Command
{
    public const DEFAULT_MAX_AGE = '24h';

    protected $signature = 'deploy:cache:prune
        {--older-than= : Delete caches untouched for longer than this, e.g. 24h, 7d (default 24h)}
        {--dry-run : Print what would be deleted and exit}';

    protected $description = 'Delete host build caches for projects that have not deployed recently';

    public function handle(): int
    {
        try {
            $window = $this->stringOption('older-than') ?? self::DEFAULT_MAX_AGE;
            $maxAge = ProjectCache::parseDuration($window);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $skip = $this->deployingNow();
        if ($skip !== []) {
            $this->line('Skipping '.implode(', ', $skip).' — deploy in flight');
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $output = (new System)->exec(
                ProjectCache::pruneArgv((int) $maxAge, $dryRun, $skip),
                [],
                900
            );
        } catch (\Exception $e) {
            $this->error('Could not prune project caches: '.$e->getMessage());

            return 1;
        }

        $rows = ProjectCache::parseReport($output);
        if ($rows === []) {
            $this->info("No project cache has been idle longer than {$window}.");

            return 0;
        }

        $this->renderReport($rows);

        $failed = array_filter($rows, static fn (array $r): bool => $r['action'] === 'failed');
        $freed = array_sum(array_map(
            static fn (array $r): int => $r['action'] === 'failed' ? 0 : $r['bytes'],
            $rows
        ));

        $this->info(sprintf(
            '%s %s across %d project(s)',
            $dryRun ? 'Would free' : 'Freed',
            $this->formatBytes($freed),
            count($rows) - count($failed)
        ));
        if ($failed !== []) {
            $this->warn(count($failed).' could not be removed');
        }

        return 0;
    }

    /**
     * Accounts with a deploy in flight, which must keep their caches.
     *
     * The script's own age check makes this nearly redundant -- a running
     * deploy stamped its cache directory minutes ago -- but "nearly" is doing
     * too much work when an operator can pass `--older-than=1m`, and deleting
     * a cache from under a build fails the deploy outright.
     *
     * @return list<string>
     */
    private function deployingNow(): array
    {
        $running = [];
        foreach (User::query()->pluck('username') as $username) {
            if (! is_string($username) || $username === '') {
                continue;
            }
            try {
                $logger = DeployLogger::current($username);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            if ($logger !== null && $logger->isRunning()) {
                $running[] = $username;
            }
        }

        return $running;
    }

    /**
     * @param  list<array{path: string, bytes: int, age: int, action: string}>  $rows
     */
    private function renderReport(array $rows): void
    {
        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                basename($row['path']),
                $this->formatAge($row['age']),
                $this->formatBytes($row['bytes']),
                $row['action'],
            ];
        }

        $this->table(['Project', 'Last deploy', 'Size', ''], $table);
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1).'d ago';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1).'h ago';
        }

        return max(0, (int) round($seconds / 60)).'m ago';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).'G';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576).'M';
        }

        return $bytes.'B';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
