<?php

namespace App\Console\Commands\Hub;

use App\Models\HubAccessLog;
use Illuminate\Console\Command;

/** Keep the log of PanelAlpha Connect's calls for 30 days. */
class HubPruneCommand extends Command
{
    protected $signature = 'hub:prune';

    protected $description = 'Drop PanelAlpha Connect access log rows older than 30 days';

    public function handle(): int
    {
        $log = HubAccessLog::query()->where('at', '<', now()->subDays(HubAccessLog::KEEP_DAYS))->delete();

        $this->line("Pruned {$log} access log row(s).");

        return self::SUCCESS;
    }
}
