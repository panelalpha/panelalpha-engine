<?php

namespace App\Console\Commands\Concerns;

use App\System;

/**
 * A stored proxy rule changes nothing until the webserver config is rebuilt
 * and reloaded; the API does both after every change, and so do the commands.
 */
trait AppliesProxyRules
{
    protected function applyProxyRules(): void
    {
        $webserver = app(System::class)->webserver();
        $webserver->rebuildConfig();
        $webserver->scheduleWebserverReloadInBackground();
    }
}
