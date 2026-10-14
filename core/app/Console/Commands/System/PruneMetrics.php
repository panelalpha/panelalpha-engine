<?php

namespace App\Console\Commands\System;

use App\Lib\Host\ProjectDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMetrics extends Command
{
    protected $signature = 'metrics:prune';

    protected $description = 'Prune old and excess metrics data';

    public function handle(): int
    {
        $daysLimit = (int)env('METRICS_LIMIT_DAYS', 90);
        $entriesLimit = (int)env('METRICS_LIMIT_ENTRIES', 1000000);

        $this->info("Pruning server_metrics...");

        $this->pruneByDaysLimit($daysLimit);
        $this->pruneByEntriesLimit($entriesLimit);

        $days = ProjectDisk::daysKept();
        $deleted = DB::table('project_disk_usage')->where('timestamp', '<', now('UTC')->subDays($days))->delete();
        $this->info("Deleted $deleted project disk measurements older than $days days.");

        $this->info("Pruning completed.");
        return 0;
    }

    private function pruneByDaysLimit(int $daysLimit): void
    {
        if ($daysLimit < 1) {
            $this->warn("METRICS_LIMIT_DAYS not set, skipping days limit pruning.");
            return;
        }

        $cutoffDate = now()->subDays($daysLimit);
        $deletedByDate = DB::table('server_metrics')
            ->where('timestamp', '<', $cutoffDate)
            ->delete();

        $this->info("Deleted $deletedByDate records older than $daysLimit days.");
    }

    private function pruneByEntriesLimit(int $entriesLimit): void
    {
        if ($entriesLimit < 1) {
            $this->warn("METRICS_LIMIT_ENTRIES not set, skipping record limit pruning.");
            return;
        }

        $totalEntries = DB::table('server_metrics')->count();

        if ($totalEntries < $entriesLimit) {
            $this->info("Entry count ($totalEntries) is within the limit ($entriesLimit).");
            return;
        }

        /** @var ?string $cutoffTimestamp */
        $cutoffTimestamp = DB::table('server_metrics')
            ->orderByDesc('timestamp')
            ->offset($entriesLimit)
            ->limit(1)
            ->value('timestamp');

        if (!$cutoffTimestamp) {
            $this->warn("No cutoff timestamp found, skipping record limit pruning.");
            return;
        }

        $deletedByCount = DB::table('server_metrics')
            ->where('timestamp', '<', $cutoffTimestamp)
            ->delete();

        $this->info("Deleted $deletedByCount oldest records to keep max $entriesLimit entries.");
    }
}
