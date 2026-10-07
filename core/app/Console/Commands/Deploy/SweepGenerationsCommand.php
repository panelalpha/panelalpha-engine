<?php

namespace App\Console\Commands\Deploy;

use App\System\Project\Dind\Generation\GenerationSweep;
use Illuminate\Console\Command;

class SweepGenerationsCommand extends Command
{
    protected $signature = 'deploy:generations:sweep';

    protected $description = 'Settle what an interrupted zero-downtime redeploy left: traffic, the second app generation, a moved-aside checkout';

    public function handle(): int
    {
        $settled = GenerationSweep::run();
        foreach ($settled as $line) {
            $this->line($line);
        }
        $this->info('Settled ' . count($settled) . ' project(s).');

        return 0;
    }
}
