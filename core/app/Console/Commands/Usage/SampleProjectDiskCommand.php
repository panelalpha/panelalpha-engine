<?php

namespace App\Console\Commands\Usage;

use App\Lib\Host\ProjectDisk;
use Illuminate\Console\Command;

/** Every 15 minutes: how much disk each project uses, files and Docker data apart. */
class SampleProjectDiskCommand extends Command
{
    protected $signature = 'metrics:projects:disk';

    protected $description = 'Record how much disk every project uses';

    public function handle(ProjectDisk $disk): int
    {
        $written = $disk->sample();

        if ($this->getOutput()->isVerbose()) {
            $this->line("{$written} project(s).");
        }

        return self::SUCCESS;
    }
}
