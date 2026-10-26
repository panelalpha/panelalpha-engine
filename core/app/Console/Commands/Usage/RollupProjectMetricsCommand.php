<?php

namespace App\Console\Commands\Usage;

use App\Lib\Host\ProjectStats;
use Illuminate\Console\Command;

/** Every hour: average the last complete hours of project samples. */
class RollupProjectMetricsCommand extends Command
{
    protected $signature = 'metrics:projects:rollup';

    protected $description = 'Average the per-minute project and container samples into hourly rows';

    public function handle(): int
    {
        $written = ProjectStats::rollup();

        if ($this->getOutput()->isVerbose()) {
            $this->line("{$written} hourly row(s).");
        }

        return self::SUCCESS;
    }
}
