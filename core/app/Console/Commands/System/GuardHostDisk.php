<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind\HostDiskGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Keep host disk from filling between the daily prunes.
 *
 * Caps host build cache at DEPLOY_HOST_BUILD_CACHE_MAX, then, while free
 * space on a deploy path is below DEPLOY_DISK_PRESSURE_FREE, frees the oldest
 * build cache, then through `system:image:prune` images unused for 6 hours,
 * halving the window until enough is free; what a deploy names stays.
 */
class GuardHostDisk extends Command
{
    /** The first window disk pressure takes images from; each further pass halves it. */
    public const PRESSURE_IMAGE_AGE = '6h';

    protected $signature = 'system:disk:guard
        {--dry-run : Print what would be freed and exit}';

    protected $description = 'Cap host build cache and free host disk when it runs low';

    private bool $dryRun = false;

    public function handle(): int
    {
        $system = app(System::class);
        $dryRun = $this->dryRun = (bool) $this->option('dry-run');

        $cap = self::bytes(config('deploy.host_build_cache_max'));
        if ($cap !== null) {
            $this->pruneBuildCache($system, Privileged::docker()->pruneBuildCacheAbove($cap), 'over the '.HostPrewarmPlan::formatBytes($cap).' cap', $dryRun);
        }

        $guard = new HostDiskGuard($system, null);
        $lowest = $guard->lowest();
        if ($lowest === null) {
            $this->warn('Could not read free space on the host.');

            return 0;
        }
        $target = self::target(config('deploy.disk_pressure_free'), $lowest['size']);
        if ($target === null || $lowest['free'] >= $target) {
            $this->line(self::state($lowest).', nothing to do.');

            return 0;
        }

        $this->report('warning', self::state($lowest).', below the '.HostPrewarmPlan::formatBytes($target).' target; freeing disk.');
        if ($dryRun) {
            $this->line('Would prune host build cache down to '.HostPrewarmPlan::formatBytes($target).' free, then images unused for '.self::PRESSURE_IMAGE_AGE.' and less.');

            return 0;
        }

        $this->pruneBuildCache($system, Privileged::docker()->pruneBuildCacheUntilFree($target), 'to reach the free-space target', false);
        $lowest = $guard->lowest() ?? $lowest;
        if ($lowest['free'] < $target) {
            Artisan::call('system:image:prune', [
                '--older-than' => self::PRESSURE_IMAGE_AGE,
                '--until-free' => (string) $target,
                '--build-cache-older-than' => 'off',
            ]);
            $this->line(trim(Artisan::output()));
            $lowest = $guard->lowest() ?? $lowest;
        }

        if ($lowest['free'] < $target) {
            $this->report('error', self::state($lowest).' after pruning; what is left is held by images in use, accounts and volumes.');
        } else {
            $this->report('info', self::state($lowest).' after pruning.');
        }

        return 0;
    }

    /**
     * A size, or null for off. Empty means 10G; an unparseable value falls
     * back to it rather than leaving the cache uncapped.
     */
    public static function bytes(mixed $value, string $default = '10G'): ?int
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if (in_array($value, ['0', 'off', 'none', 'false'], true)) {
            return null;
        }
        try {
            return HostPrewarmPlan::parseBytes($value === '' ? $default : $value);
        } catch (\InvalidArgumentException $e) {
            return HostPrewarmPlan::parseBytes($default);
        }
    }

    /** Free bytes wanted on a filesystem of $size: "15%", "20G", or null for off. */
    public static function target(mixed $value, int $size): ?int
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if ($value === '') {
            $value = '15%';
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*%$/', $value, $m) === 1) {
            $percent = min(90.0, (float) $m[1]);

            return $percent > 0 ? (int) round($size * $percent / 100) : null;
        }

        return self::bytes($value, '0');
    }

    /** @param list<string> $prune */
    private function pruneBuildCache(System $system, array $prune, string $why, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line("Would prune host build cache {$why}.");

            return;
        }
        try {
            $out = $system->exec($prune, [], 300);
        } catch (\Exception $e) {
            $this->report('warning', 'Could not prune the host build cache: '.trim($e->getMessage()));

            return;
        }
        $freed = self::freed($out);
        if ($freed > 0) {
            $this->report('info', 'Pruned '.HostPrewarmPlan::formatBytes($freed)." of host build cache {$why}.");
        }
    }

    /** The `Total:` line buildx prints, in bytes; 0 when absent. */
    public static function freed(string $output): int
    {
        if (preg_match('/^Total:\s*(\S+)/m', $output, $m) !== 1) {
            return 0;
        }
        try {
            return (int) HostPrewarmPlan::parseBytes($m[1]);
        } catch (\InvalidArgumentException $e) {
            return 0;
        }
    }

    /** @param array{path: string, free: int, size: int} $usage */
    private static function state(array $usage): string
    {
        return sprintf('%s free of %s on %s', HostPrewarmPlan::formatBytes($usage['free']), HostPrewarmPlan::formatBytes($usage['size']), $usage['path']);
    }

    /** Console and the Laravel log: scheduled output goes nowhere. A dry run only prints. */
    private function report(string $level, string $message): void
    {
        match ($level) {
            'error' => $this->error($message),
            'warning' => $this->warn($message),
            default => $this->info($message),
        };
        if (! $this->dryRun) {
            Log::log($level, 'system:disk:guard: '.$message);
        }
    }
}
