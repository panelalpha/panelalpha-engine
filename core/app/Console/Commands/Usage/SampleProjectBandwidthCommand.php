<?php

namespace App\Console\Commands\Usage;

use App\Lib\Usage\ProjectBandwidth;
use Illuminate\Console\Command;

/** Hourly: each project's transfer this calendar month, as the project resource shows it. */
class SampleProjectBandwidthCommand extends Command
{
    protected $signature = 'metrics:projects:bandwidth';

    protected $description = 'Record every project\'s transfer this calendar month';

    public function handle(ProjectBandwidth $bandwidth): int
    {
        $measured = $bandwidth->sample();

        if ($this->getOutput()->isVerbose()) {
            $this->line("{$measured} project(s).");
        }

        return self::SUCCESS;
    }
}
