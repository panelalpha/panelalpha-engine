<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

class RebuildDomains extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:rebuild-domains'];

    protected $signature = 'system:domain:rebuild';

    protected $description = 'Recreate configuration files for domains from database.';

    public function handle(): int
    {
        try {
            $system = new System;
            $system->webserver()->rebuildDomains();
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

        return 0;
    }
}
