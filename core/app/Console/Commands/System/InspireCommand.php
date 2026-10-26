<?php

namespace App\Console\Commands\System;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

class InspireCommand extends Command
{
    protected $signature = 'inspire';

    protected $description = 'Display an inspiring quote';

    public function handle(): int
    {
        $this->comment(Inspiring::quote());

        return 0;
    }
}
