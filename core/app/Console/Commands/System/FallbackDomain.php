<?php

namespace App\Console\Commands\System;

use Illuminate\Console\Command;

class FallbackDomain extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:fallback-domain'];

    protected $signature = 'system:domain:fallback';

    protected $description = 'Manage webserver fallback vhost domain.';

    public function handle(): int
    {
        $this->error('TODO');

        return 1;
    }
}
