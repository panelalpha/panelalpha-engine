<?php

namespace App\Jobs;

use App\Support\JobBudget;
use App\System;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-renders every vhost and reloads the webserver, for a change that reaches them all. */
class RebuildDomainConfigs implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        (new System)->webserver()->rebuildDomains();
    }
}
