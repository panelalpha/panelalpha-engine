<?php

namespace App\Console\Commands\Usage;

use App\Lib\Host\ProjectStats;
use Illuminate\Console\Command;

/** Every minute: CPU and memory of each project and of every container inside it. */
class SampleProjectMetricsCommand extends Command
{
    protected $signature = 'metrics:projects:sample';

    protected $description = 'Record CPU and memory of every project and of the containers inside it';

    public function handle(ProjectStats $stats): int
    {
        $written = $stats->sample();

        if ($this->getOutput()->isVerbose()) {
            $this->line("{$written['projects']} project(s), {$written['containers']} container(s).");
        }

        return self::SUCCESS;
    }
}
